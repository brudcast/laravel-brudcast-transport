<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Transport;

use Brudcast\LaravelTransport\Exceptions\BrudcastException;
use Brudcast\LaravelTransport\Payload;
use Composer\InstalledVersions;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use JsonException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

final class BrudcastApiTransport extends AbstractTransport
{
    public const DEFAULT_URL = 'https://mailing-service.brudcast.com';

    public const SEND_PATH = '/api/v1/send';

    /**
     * Request timeout, in seconds
     */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * Connection timeout, in seconds
     */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    public const MESSAGE_ID_DOMAIN = 'brudcast-mailing-service';

    public const JOB_ID_HEADER = 'X-Brudcast-Job-Id';

    public const PACKAGE_NAME = 'brudcast/laravel-brudcast-transport';

    /**
     * A control character, whitespace as JavaScript's `\s` matches it, or a backslash: URL parsers
     * disagree on those, so an endpoint containing one is refused rather than read differently
     * than intended
     */
    private const AMBIGUOUS_URL_PATTERN = '/[\x{00}-\x{1F}\x{7F} \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}\\\\]/u';

    /**
     * An absolute URL starts with a scheme and `://`, so `https:example.com` is not one
     */
    private const URL_START_PATTERN = '/^[A-Za-z][A-Za-z0-9+.-]*:\/\//';

    /**
     * A sending key is visible ASCII, which is all an HTTP header value can carry unchanged
     */
    private const KEY_PATTERN = '/^[\x21-\x7E]+$/D';

    /**
     * @param  bool  $insecure  Allow plain HTTP, for a mailing service reached over a private network
     *
     * @throws InvalidArgumentException When the key contains a character other than visible ASCII,
     *                                  or the URL is not valid, includes credentials, or is not
     *                                  HTTPS, or is plain HTTP without `$insecure`
     */
    public function __construct(
        private readonly ClientInterface $client,
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly string $url = self::DEFAULT_URL,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        bool $insecure = false,
    ) {
        parent::__construct($dispatcher, $logger);

        // Checked here, so a key HTTP cannot carry never reaches an error message on the way out
        if (preg_match(self::KEY_PATTERN, $apiKey) !== 1) {
            throw new InvalidArgumentException('The Brudcast sending key contains characters that are not allowed.');
        }
        self::assertSecure($url, $insecure);
    }

    /**
     * Check that the service is reachable and accepts the sending key, without sending an email
     *
     * An empty message fails validation with a 422, which the service only returns once it has
     * accepted the key. Only the service's own validation response counts, so a 422 from a proxy
     * in front of it does not. The sending domain is not checked, so a key whose domain is not
     * registered (400) or is blocked (403) still passes.
     *
     * @throws BrudcastException When the key is not accepted
     * @throws TransportException When the service cannot be reached
     */
    public function verify(): true
    {
        [$status, $body, $retryAfter] = $this->post([]);
        $errors = $body['errors'] ?? null;
        if ($status === 422 && ($body['status'] ?? null) === false && is_array($errors) && array_is_list($errors)) {
            return true;
        }

        throw BrudcastException::fromResponse($status, $body, $retryAfter);
    }

    public function __toString(): string
    {
        return 'brudcast+api://'.(new Uri($this->url))->getHost();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException(sprintf(
                'The Brudcast transport requires a %s, %s given.',
                Message::class,
                get_debug_type($original),
            ));
        }

        [$status, $body, $retryAfter] = $this->post(Payload::fromEmail(MessageConverter::toEmail($original), $message->getEnvelope()));
        if ($status < 200 || $status >= 300 || ($body['status'] ?? true) === false) {
            throw BrudcastException::fromResponse($status, $body, $retryAfter);
        }

        $jobId = $body['data']['jobId'] ?? null;
        if (is_string($jobId) && $jobId !== '') {
            $message->setMessageId($jobId.'@'.self::MESSAGE_ID_DOMAIN);
            $original->getHeaders()->addTextHeader(self::JOB_ID_HEADER, $jobId);
        }

        $responseMessage = $body['message'] ?? null;
        $message->appendDebug($status.' '.(is_string($responseMessage) && $responseMessage !== '' ? $responseMessage : 'Accepted'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{int, array<mixed>, ?int} The status, the body and the `Retry-After` seconds
     *
     * @throws TransportException When the payload cannot be encoded, or the service cannot be reached
     */
    private function post(array $payload): array
    {
        try {
            $json = json_encode(
                $payload !== [] ? $payload : new stdClass,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new TransportException('Could not encode the message as JSON: '.$exception->getMessage(), 0, $exception);
        }

        try {
            $response = $this->client->request('POST', self::sendUrl($this->url), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'laravel-brudcast-transport/'.self::version(),
                ],
                'body' => $json,
                'http_errors' => false,
                // A redirected POST turns into a GET without its body
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException $exception) {
            $response = self::receivedResponse($exception);
            if ($response === null) {
                throw new TransportException(
                    'Could not reach the Brudcast mailing service: '.$exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        $status = $response->getStatusCode();
        $retryAfter = BrudcastException::parseRetryAfter($response->getHeaderLine('Retry-After'));
        if ($status >= 300 && $status < 400) {
            return [$status, [
                'status' => false,
                'message' => sprintf(
                    'The Brudcast endpoint redirected to "%s"; set `endpoint` to the final URL.',
                    $response->getHeaderLine('Location'),
                ),
            ], $retryAfter];
        }

        $text = self::bodyText($response);
        $body = $text !== '' ? json_decode($text, true) : null;
        if (is_array($body)) {
            return [$status, $body, $retryAfter];
        }

        // A proxy in front of the service may answer a 2xx with its own body. The message may be
        // queued by then, so it counts as sent rather than failing and being sent again on retry.
        $successful = $status >= 200 && $status < 300;

        return [
            $status,
            $successful || $text === '' ? ['status' => $successful] : ['status' => false, 'message' => $text],
            $retryAfter,
        ];
    }

    /**
     * The response whose status arrived before the transfer failed, such as a timeout or reset
     * mid-body, or null when the service was never reached
     *
     * Its status is used as it is and its body counts as empty, so a 2xx still counts as sent: the
     * message may be queued by then, and reporting the service as unreachable would invite a retry
     * that sends it twice. Guzzle 8 attaches it as a ResponseException, Guzzle 7 to a
     * RequestException.
     */
    private static function receivedResponse(GuzzleException $exception): ?ResponseInterface
    {
        $response = method_exists($exception, 'getResponse') ? $exception->getResponse() : null;

        return $response instanceof ResponseInterface ? $response->withBody(Utils::streamFor('')) : null;
    }

    /**
     * The response body as text, empty when it cannot be read to the end
     */
    private static function bodyText(ResponseInterface $response): string
    {
        try {
            return (string) $response->getBody();
        } catch (RuntimeException) {
            return '';
        }
    }

    /**
     * The send path is appended to any path the URL already has, so the service can sit behind a
     * prefix. A query string or fragment is dropped, since the path would otherwise land inside it.
     */
    private static function sendUrl(string $url): string
    {
        return rtrim((string) preg_replace('/[?#].*/s', '', $url), '/').self::SEND_PATH;
    }

    /**
     * The sending key travels in the Authorization header, so plain HTTP is only allowed when the
     * mailer opts in with `insecure`, and the URL must not carry credentials of its own
     *
     * @throws InvalidArgumentException When the URL is not valid, includes credentials, or is not
     *                                  HTTPS, or is plain HTTP without `$insecure`
     */
    private static function assertSecure(string $url, bool $insecure): void
    {
        try {
            $uri = preg_match(self::AMBIGUOUS_URL_PATTERN, $url) === 0 && preg_match(self::URL_START_PATTERN, $url) === 1
                ? new Uri($url)
                : null;
        } catch (MalformedUriException) {
            $uri = null;
        }
        if ($uri === null || $uri->getHost() === '') {
            throw new InvalidArgumentException(sprintf('The Brudcast endpoint "%s" is not a valid URL.', self::redact($url)));
        }

        // Checked before the scheme, so that message never echoes the credentials. An `@` with empty
        // credentials, as in `https://@host`, is refused too, since Guzzle reports no user info
        if ($uri->getUserInfo() !== '' || str_contains((string) preg_replace('/^[^:]*:\/\/([^\/?#]*).*/sD', '$1', $url), '@')) {
            throw new InvalidArgumentException('The Brudcast endpoint must not include a username or password.');
        }

        $scheme = $uri->getScheme();
        if ($scheme !== 'https' && ! ($scheme === 'http' && $insecure)) {
            throw new InvalidArgumentException(sprintf(
                'The Brudcast endpoint "%s" must be an https:// URL; set the mailer\'s `insecure` option to allow http://.',
                self::redact($url),
            ));
        }
    }

    /**
     * The URL as an error message may show it: the query string and fragment dropped, since either
     * can carry a secret, then everything from `://` (or the start, when there is none) to the last
     * `@` replaced with `***`, so credentials never show however malformed the URL is
     */
    private static function redact(string $url): string
    {
        $url = (string) preg_replace('/[?#].*/s', '', $url);
        $scheme = strpos($url, '://');
        $start = $scheme === false ? 0 : $scheme + 3;
        $at = strrpos($url, '@');

        return $at === false || $at < $start ? $url : substr($url, 0, $start).'***'.substr($url, $at);
    }

    private static function version(): string
    {
        return self::releaseVersion(
            InstalledVersions::isInstalled(self::PACKAGE_NAME)
                ? InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'dev'
                : 'dev'
        );
    }

    /**
     * The version without a leading "v" or a build suffix, so a tag such as `v1.0.0` or a checkout
     * reported as `1.0.0+no-version-set` both read as `1.0.0`
     */
    private static function releaseVersion(string $version): string
    {
        return (string) preg_replace(['/^v/i', '/\+.*$/sD'], '', $version);
    }
}

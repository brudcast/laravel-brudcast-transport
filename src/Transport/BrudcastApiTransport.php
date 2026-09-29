<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Transport;

use Brudcast\LaravelTransport\Exceptions\BrudcastException;
use Brudcast\LaravelTransport\Payload;
use Composer\InstalledVersions;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
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
     * @param  bool  $insecure  Allow plain HTTP, for a mailing service reached over a private network
     *
     * @throws InvalidArgumentException When the URL is not HTTPS, or is plain HTTP without `$insecure`
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

        self::assertSecure($url, $insecure);
    }

    /**
     * Check that the service is reachable and accepts the sending key, without sending an email
     *
     * An empty message fails validation with a 422, which the service only returns once it has
     * accepted the key.
     *
     * @throws BrudcastException When the key is not accepted
     * @throws TransportException When the service cannot be reached
     */
    public function verify(): true
    {
        [$status, $body] = $this->post([]);
        if ($status === 422) {
            return true;
        }

        throw BrudcastException::fromResponse($status, $body);
    }

    public function __toString(): string
    {
        return 'brudcast+api://'.(parse_url($this->url, PHP_URL_HOST) ?: $this->url);
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

        [$status, $body] = $this->post(Payload::fromEmail(MessageConverter::toEmail($original), $message->getEnvelope()));
        if ($status < 200 || $status >= 300 || ($body['status'] ?? true) === false) {
            throw BrudcastException::fromResponse($status, $body);
        }

        $jobId = $body['data']['jobId'] ?? null;
        if (is_string($jobId) && $jobId !== '') {
            $message->setMessageId($jobId.'@'.self::MESSAGE_ID_DOMAIN);
            $original->getHeaders()->addTextHeader(self::JOB_ID_HEADER, $jobId);
        }

        $responseMessage = $body['message'] ?? null;
        $message->appendDebug($status.' '.(is_string($responseMessage) ? $responseMessage : 'Accepted'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{int, array<mixed>}
     *
     * @throws TransportException When the service cannot be reached
     */
    private function post(array $payload): array
    {
        try {
            $response = $this->client->request('POST', self::sendUrl($this->url), [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'User-Agent' => 'laravel-brudcast-transport/'.self::version(),
                ],
                'json' => $payload !== [] ? $payload : new stdClass,
                'http_errors' => false,
                // A redirected POST turns into a GET without its body
                'allow_redirects' => false,
            ]);
        } catch (GuzzleException $exception) {
            throw new TransportException(
                'Could not reach the Brudcast mailing service: '.$exception->getMessage(),
                0,
                $exception,
            );
        }

        $status = $response->getStatusCode();
        if ($status >= 300 && $status < 400) {
            return [$status, [
                'status' => false,
                'message' => sprintf(
                    'The Brudcast endpoint redirected to "%s"; set `endpoint` to the final URL.',
                    $response->getHeaderLine('Location'),
                ),
            ]];
        }

        $text = (string) $response->getBody();
        $body = $text !== '' ? json_decode($text, true) : null;
        if (is_array($body)) {
            return [$status, $body];
        }

        // A proxy in front of the service may answer a 2xx with its own body. The message may be
        // queued by then, so it counts as sent rather than failing and being sent again on retry.
        $successful = $status >= 200 && $status < 300;

        return [$status, $successful || $text === '' ? ['status' => $successful] : ['status' => false, 'message' => $text]];
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
     * mailer opts in with `insecure`
     *
     * @throws InvalidArgumentException When the URL is not HTTPS, or is plain HTTP without `$insecure`
     */
    private static function assertSecure(string $url, bool $insecure): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($host === '' || ($scheme !== 'https' && ! ($scheme === 'http' && $insecure))) {
            throw new InvalidArgumentException(sprintf(
                'The Brudcast endpoint "%s" must be an https:// URL; set the mailer\'s `insecure` option to allow http://.',
                $url,
            ));
        }
    }

    private static function version(): string
    {
        return InstalledVersions::isInstalled(self::PACKAGE_NAME)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) ?? 'dev'
            : 'dev';
    }
}

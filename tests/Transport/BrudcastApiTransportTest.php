<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests\Transport;

use Brudcast\LaravelTransport\Exceptions\BrudcastException;
use Brudcast\LaravelTransport\Transport\BrudcastApiTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

final class BrudcastApiTransportTest extends TestCase
{
    private MockHandler $responses;

    protected function setUp(): void
    {
        $this->responses = new MockHandler;
    }

    private function transport(string $url = BrudcastApiTransport::DEFAULT_URL, bool $insecure = false): BrudcastApiTransport
    {
        $handler = HandlerStack::create($this->responses);

        return new BrudcastApiTransport(new Client(['handler' => $handler]), 'bk_test_key', $url, insecure: $insecure);
    }

    private function email(): Email
    {
        return (new Email)
            ->from('hello@acme.com')
            ->to('customer@example.com')
            ->subject('Welcome')
            ->text('Thanks for signing up.');
    }

    /**
     * @param  array<mixed>  $body
     */
    private function respond(int $status, array $body): void
    {
        $this->responses->append(new Response($status, [], (string) json_encode($body)));
    }

    private function accepted(string $jobId = 'job-123'): void
    {
        $this->respond(202, ['status' => true, 'message' => 'Email queued for delivery', 'data' => ['jobId' => $jobId]]);
    }

    private function lastRequest(): RequestInterface
    {
        $request = $this->responses->getLastRequest();
        self::assertNotNull($request, 'No request was sent');

        return $request;
    }

    /**
     * @return array<mixed>
     */
    private function lastBody(): array
    {
        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    public function test_posts_the_message_to_the_send_endpoint(): void
    {
        $this->accepted();

        $this->transport()->send($this->email());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://mailing-service.brudcast.com/api/v1/send', (string) $request->getUri());
        self::assertSame('Bearer bk_test_key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertStringStartsWith('laravel-brudcast-transport/', $request->getHeaderLine('User-Agent'));
        self::assertSame([
            'from' => ['address' => 'hello@acme.com'],
            'to' => [['address' => 'customer@example.com']],
            'subject' => 'Welcome',
            'text' => 'Thanks for signing up.',
        ], $this->lastBody());
    }

    public function test_encodes_the_body_without_escaping_unicode_or_slashes(): void
    {
        $this->accepted();

        $this->transport()->send($this->email()->subject('Café à Zürich')->html('<p>Hi</p>'));

        $body = (string) $this->lastRequest()->getBody();
        self::assertStringContainsString('"subject":"Café à Zürich"', $body);
        self::assertStringContainsString('"html":"<p>Hi</p>"', $body);
    }

    public function test_throws_before_sending_when_the_message_cannot_be_encoded_as_json(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not encode the message as JSON: Malformed UTF-8 characters');

        try {
            $this->transport()->send($this->email()->subject("Welcome \xB1\x31"));
        } finally {
            self::assertNull($this->responses->getLastRequest());
        }
    }

    public function test_throws_before_sending_when_an_attachment_is_empty(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The attachment "empty.txt" is empty.');

        try {
            $this->transport()->send($this->email()->attach('', 'empty.txt', 'text/plain'));
        } finally {
            self::assertNull($this->responses->getLastRequest());
        }
    }

    public function test_sends_the_release_version_in_the_user_agent(): void
    {
        $this->accepted();

        $this->transport()->send($this->email());

        self::assertMatchesRegularExpression(
            '#^laravel-brudcast-transport/[^vV+][^+]*$#',
            $this->lastRequest()->getHeaderLine('User-Agent'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function versions(): iterable
    {
        yield 'a tag' => ['v1.2.0', '1.2.0'];
        yield 'an uppercase tag' => ['V1.2.0', '1.2.0'];
        yield 'a build suffix' => ['v1.0.0+no-version-set', '1.0.0'];
        yield 'a plain version' => ['1.2.0', '1.2.0'];
        yield 'only one leading v' => ['vv1.2.0', 'v1.2.0'];
        yield 'a branch' => ['dev-main', 'dev-main'];
    }

    #[DataProvider('versions')]
    public function test_strips_the_leading_v_and_build_suffix_from_the_version(string $version, string $expected): void
    {
        $releaseVersion = new ReflectionMethod(BrudcastApiTransport::class, 'releaseVersion');

        self::assertSame($expected, $releaseVersion->invoke(null, $version));
    }

    public function test_joins_a_custom_url_with_the_send_path(): void
    {
        $this->accepted();

        $this->transport('https://mail.example.com/')->send($this->email());

        self::assertSame('https://mail.example.com/api/v1/send', (string) $this->lastRequest()->getUri());
    }

    public function test_drops_the_query_and_fragment_of_a_custom_url(): void
    {
        $this->accepted();

        $this->transport('https://proxy.example.com/brudcast/?region=eu#top')->send($this->email());

        self::assertSame('https://proxy.example.com/brudcast/api/v1/send', (string) $this->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function insecureUrls(): iterable
    {
        yield 'plain http to a remote host' => ['http://mailing-service.brudcast.com'];
        yield 'another scheme' => ['ftp://mailing-service.brudcast.com'];
        yield 'localhost' => ['http://localhost:3000'];
        yield 'IPv4 loopback' => ['http://127.0.0.1:3000'];
        yield 'IPv6 loopback' => ['http://[::1]:3000'];
        yield 'a host that only starts like a loopback address' => ['http://127.0.0.1.example.com'];
    }

    #[DataProvider('insecureUrls')]
    public function test_refuses_endpoints_that_would_send_the_key_in_the_clear(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https:// URL');

        $this->transport($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedUrls(): iterable
    {
        yield 'no scheme' => ['mailing-service.brudcast.com'];
        yield 'no host' => ['https:///api'];
        yield 'an invalid port' => ['https://mailing-service.brudcast.com:99999'];
        yield 'leading whitespace' => [' https://mailing-service.brudcast.com'];
        yield 'trailing newline' => ["https://mailing-service.brudcast.com\n"];
        yield 'a space in the host' => ['https://mailing service.brudcast.com'];
        yield 'a tab in the path' => ["https://mailing-service.brudcast.com/a\tb"];
        yield 'a no-break space' => ["https://mailing-service.brudcast.com\u{A0}"];
        yield 'a byte order mark' => ["\u{FEFF}https://mailing-service.brudcast.com"];
        yield 'a backslash' => ['https://mailing-service.brudcast.com\\api'];
        yield 'invalid UTF-8' => ["https://mailing-service.brudcast.com/\xFF"];
        yield 'no slashes after the scheme' => ['https:mailing-service.brudcast.com'];
        yield 'one slash after the scheme' => ['https:/mailing-service.brudcast.com'];
        yield 'a host and port without a scheme' => ['mailing-service:3000'];
        yield 'a NUL byte' => ["https://mailing-service.brudcast.com/\0"];
        yield 'an escape character' => ["https://mailing-service.brudcast.com/\x1B"];
        yield 'a delete character' => ["https://mailing-service.brudcast.com/\x7F"];
    }

    #[DataProvider('malformedUrls')]
    public function test_refuses_endpoints_that_are_not_valid_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('The Brudcast endpoint "%s" is not a valid URL.', $url));

        $this->transport($url, insecure: true);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urlsWithCredentials(): iterable
    {
        yield 'a username and password' => ['https://user:s3cret@mailing-service.brudcast.com', false];
        yield 'a username' => ['https://user@mailing-service.brudcast.com', false];
        yield 'an empty username' => ['https://@mailing-service.brudcast.com', false];
        yield 'plain http' => ['http://user:s3cret@mailing-service:3000', false];
        yield 'plain http when insecure' => ['http://user:s3cret@mailing-service:3000', true];
    }

    #[DataProvider('urlsWithCredentials')]
    public function test_refuses_endpoints_with_credentials_without_echoing_them(string $url, bool $insecure): void
    {
        try {
            $this->transport($url, $insecure);
            self::fail('The endpoint was accepted');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The Brudcast endpoint must not include a username or password.', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function redactedUrls(): iterable
    {
        yield 'credentials in an invalid URL' => [
            'https://user:s3cret@mail example.com/api',
            false,
            'The Brudcast endpoint "https://***@mail example.com/api" is not a valid URL.',
        ];
        yield 'an @ in the password' => [
            'https://user:p@ss@mail example.com',
            false,
            'The Brudcast endpoint "https://***@mail example.com" is not a valid URL.',
        ];
        yield 'a query and fragment in an invalid URL' => [
            'https://mail example.com/api?token=s3cret#s3cret',
            false,
            'The Brudcast endpoint "https://mail example.com/api" is not a valid URL.',
        ];
        yield 'an @ after the path starts' => [
            'https://mail example.com/a@b',
            false,
            'The Brudcast endpoint "https://***@b" is not a valid URL.',
        ];
        yield 'credentials without //' => [
            'https:user:s3cret@mail.example.com',
            false,
            'The Brudcast endpoint "***@mail.example.com" is not a valid URL.',
        ];
        yield 'credentials after a single slash' => [
            'https:/user:s3cret@mail.example.com',
            false,
            'The Brudcast endpoint "***@mail.example.com" is not a valid URL.',
        ];
        yield 'an @ after the query starts' => [
            'https://mail example.com?to=a@b',
            false,
            'The Brudcast endpoint "https://mail example.com" is not a valid URL.',
        ];
        yield 'a query in a plain HTTP URL' => [
            'http://mail.example.com/api?token=s3cret#s3cret',
            false,
            'The Brudcast endpoint "http://mail.example.com/api" must be an https:// URL; set the mailer\'s `insecure` option to allow http://.',
        ];
        yield 'a query in another scheme' => [
            'ftp://mail.example.com?token=s3cret',
            true,
            'The Brudcast endpoint "ftp://mail.example.com" must be an https:// URL; set the mailer\'s `insecure` option to allow http://.',
        ];
    }

    #[DataProvider('redactedUrls')]
    public function test_redacts_credentials_query_and_fragment_from_endpoint_errors(string $url, bool $insecure, string $message): void
    {
        try {
            $this->transport($url, $insecure);
            self::fail('The endpoint was accepted');
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysWithDisallowedCharacters(): iterable
    {
        yield 'empty' => [''];
        yield 'a space' => ['bk_test key'];
        yield 'a line feed' => ["bk_test\nkey"];
        yield 'a carriage return and line feed' => ["bk_test\r\nX-Injected: 1"];
        yield 'a NUL byte' => ["bk_test\0key"];
        yield 'a trailing line feed' => ["bk_test_key\n"];
        yield 'a non-ASCII character' => ['bk_tést_key'];
    }

    #[DataProvider('keysWithDisallowedCharacters')]
    public function test_refuses_a_key_with_characters_other_than_visible_ascii(string $key): void
    {
        try {
            new BrudcastApiTransport(new Client(['handler' => HandlerStack::create($this->responses)]), $key);
            self::fail('The key was accepted');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The Brudcast sending key contains characters that are not allowed.', $exception->getMessage());
        }
    }

    public function test_accepts_every_visible_ascii_character_in_the_key(): void
    {
        $key = implode('', array_map('chr', range(0x21, 0x7E)));
        $this->accepted();

        (new BrudcastApiTransport(new Client(['handler' => HandlerStack::create($this->responses)]), $key))->send($this->email());

        self::assertSame('Bearer '.$key, $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_allows_plain_http_to_any_host_when_insecure(): void
    {
        $this->accepted();

        $this->transport('http://mailing-service:3000', insecure: true)->send($this->email());

        self::assertSame('http://mailing-service:3000/api/v1/send', (string) $this->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'another scheme' => ['ftp://mailing-service:3000'];
        yield 'a WebSocket scheme' => ['ws://mailing-service:3000'];
    }

    #[DataProvider('invalidUrls')]
    public function test_still_requires_an_http_url_when_insecure(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https:// URL');

        $this->transport($url, insecure: true);
    }

    public function test_does_not_follow_redirects(): void
    {
        $this->responses->append(
            new Response(301, ['Location' => 'https://mail.brudcast.com/api/v1/send']),
            new Response(202, [], '{"status":true,"data":{"jobId":"job-123"}}'),
        );

        try {
            $this->transport()->send($this->email());
            self::fail('The redirect was followed');
        } catch (BrudcastException $exception) {
            self::assertSame(301, $exception->getStatusCode());
            self::assertSame(
                'The Brudcast endpoint redirected to "https://mail.brudcast.com/api/v1/send"; set `endpoint` to the final URL.',
                $exception->getMessage(),
            );
        }

        self::assertCount(1, $this->responses);
    }

    public function test_uses_the_job_id_as_the_message_id(): void
    {
        $this->accepted('5f0c1e');

        $sent = $this->transport()->send($this->email());

        self::assertNotNull($sent);
        self::assertSame('5f0c1e@brudcast-mailing-service', $sent->getMessageId());
        $original = $sent->getOriginalMessage();
        self::assertInstanceOf(Message::class, $original);
        self::assertSame('5f0c1e', $original->getHeaders()->getHeaderBody('X-Brudcast-Job-Id'));
        self::assertStringContainsString('202 Email queued for delivery', $sent->getDebug());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function missingJobIds(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'a number' => [123];
    }

    #[DataProvider('missingJobIds')]
    public function test_keeps_the_message_id_without_a_job_id(mixed $jobId): void
    {
        $this->respond(202, ['status' => true, 'data' => ['jobId' => $jobId]]);

        $sent = $this->transport()->send($this->email());

        self::assertNotNull($sent);
        self::assertStringEndsNotWith('@brudcast-mailing-service', $sent->getMessageId());
        $original = $sent->getOriginalMessage();
        self::assertInstanceOf(Message::class, $original);
        self::assertFalse($original->getHeaders()->has('X-Brudcast-Job-Id'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableMessages(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'a number' => [42];
        yield 'a list' => [['queued']];
    }

    #[DataProvider('unusableMessages')]
    public function test_reports_accepted_without_a_usable_message(mixed $message): void
    {
        $this->respond(202, ['status' => true, 'message' => $message, 'data' => ['jobId' => 'job-123']]);

        $sent = $this->transport()->send($this->email());

        self::assertNotNull($sent);
        self::assertStringContainsString('202 Accepted', $sent->getDebug());
    }

    #[DataProvider('unusableMessages')]
    public function test_falls_back_to_the_status_without_a_usable_message(mixed $message): void
    {
        $this->respond(400, ['status' => false, 'message' => $message]);

        $this->expectException(BrudcastException::class);
        $this->expectExceptionMessage('Brudcast responded with HTTP 400');

        $this->transport()->send($this->email());
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failures(): iterable
    {
        yield 'unregistered domain' => [400, 'Domain not registered'];
        yield 'unknown key' => [401, 'Invalid sending key'];
        yield 'blocked sending' => [403, 'Sending is blocked'];
        yield 'rate limited' => [429, 'Too many requests'];
        yield 'unavailable' => [503, 'Service unavailable'];
    }

    #[DataProvider('failures')]
    public function test_throws_the_service_message_and_status(int $status, string $message): void
    {
        $this->respond($status, ['status' => false, 'message' => $message]);

        try {
            $this->transport()->send($this->email());
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertSame($status, $exception->getStatusCode());
            self::assertSame(['status' => false, 'message' => $message], $exception->getResponse());
        }
    }

    /**
     * @return iterable<string, array{mixed, list<mixed>}>
     */
    public static function errorLists(): iterable
    {
        yield 'a list' => [[['path' => ['to'], 'message' => 'Required']], [['path' => ['to'], 'message' => 'Required']]];
        yield 'an object' => [['to' => 'Required', 'subject' => 'Required'], ['Required', 'Required']];
        yield 'a string' => ['Required', []];
        yield 'missing' => [null, []];
    }

    /**
     * @param  list<mixed>  $expected
     */
    #[DataProvider('errorLists')]
    public function test_always_exposes_validation_issues_as_a_list(mixed $errors, array $expected): void
    {
        $this->respond(422, ['status' => false, 'message' => 'Validation failed', 'errors' => $errors]);

        try {
            $this->transport()->send($this->email());
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertSame($expected, $exception->getErrors());
        }
    }

    public function test_exposes_validation_issues(): void
    {
        $errors = [['path' => ['to', 0, 'address'], 'message' => 'Invalid email']];
        $this->respond(422, ['status' => false, 'message' => 'Validation failed', 'errors' => $errors]);

        try {
            $this->transport()->send($this->email());
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertSame($errors, $exception->getErrors());
        }
    }

    public function test_throws_when_a_successful_status_carries_a_failed_body(): void
    {
        $this->respond(200, ['status' => false, 'message' => 'Rejected']);

        $this->expectException(BrudcastException::class);
        $this->expectExceptionMessage('Rejected');

        $this->transport()->send($this->email());
    }

    public function test_uses_the_raw_body_when_it_is_not_json(): void
    {
        $this->responses->append(new Response(502, [], 'Bad Gateway'));

        $this->expectException(BrudcastException::class);
        $this->expectExceptionMessage('Bad Gateway');

        $this->transport()->send($this->email());
    }

    public function test_counts_a_successful_status_with_an_empty_body_as_sent(): void
    {
        $this->responses->append(new Response(202));

        $sent = $this->transport()->send($this->email());

        self::assertNotNull($sent);
        self::assertStringContainsString('202 Accepted', $sent->getDebug());
    }

    public function test_counts_a_successful_status_without_json_as_sent(): void
    {
        $this->responses->append(new Response(202, [], '<html>Accepted</html>'));

        $sent = $this->transport()->send($this->email());

        self::assertNotNull($sent);
        self::assertStringContainsString('202 Accepted', $sent->getDebug());
    }

    public function test_falls_back_to_the_status_when_the_body_is_empty(): void
    {
        $this->responses->append(new Response(500));

        $this->expectException(BrudcastException::class);
        $this->expectExceptionMessage('Brudcast responded with HTTP 500');

        $this->transport()->send($this->email());
    }

    /**
     * @return iterable<string, array{array<string, string>, int|null}>
     */
    public static function retryAfterHeaders(): iterable
    {
        yield 'seconds' => [['Retry-After' => '120'], 120];
        yield 'zero seconds' => [['Retry-After' => '0'], 0];
        yield 'a date in the past' => [['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT'], 0];
        yield 'a negative number' => [['Retry-After' => '-5'], null];
        yield 'a fraction' => [['Retry-After' => '1.5'], null];
        yield 'a word' => [['Retry-After' => 'soon'], null];
        yield 'missing' => [[], null];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('retryAfterHeaders')]
    public function test_exposes_the_retry_after_header(array $headers, ?int $seconds): void
    {
        $this->responses->append(new Response(429, $headers, '{"status":false,"message":"Too many requests"}'));

        try {
            $this->transport()->send($this->email());
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertSame($seconds, $exception->getRetryAfter());
        }
    }

    public function test_exposes_a_retry_after_date_as_seconds_from_now(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 90);
        $this->responses->append(new Response(503, ['Retry-After' => $date], 'Service Unavailable'));

        try {
            $this->transport()->send($this->email());
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertGreaterThanOrEqual(88, $exception->getRetryAfter());
            self::assertLessThanOrEqual(90, $exception->getRetryAfter());
        }
    }

    public function test_exposes_the_retry_after_header_from_verify(): void
    {
        $this->responses->append(new Response(429, ['Retry-After' => '30'], '{"status":false,"message":"Too many requests"}'));

        try {
            $this->transport()->verify();
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertSame(30, $exception->getRetryAfter());
        }
    }

    public function test_wraps_network_failures_in_a_transport_exception(): void
    {
        $this->responses->append(new ConnectException('Connection refused', new Request('POST', '/')));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach the Brudcast mailing service: Connection refused');

        $this->transport()->send($this->email());
    }

    public function test_counts_a_2xx_whose_transfer_fails_mid_body_as_sent(): void
    {
        if (! class_exists(ResponseException::class)) {
            self::markTestSkipped('Guzzle 8 attaches the received response as a ResponseException');
        }
        $this->responses->append(new ResponseException('cURL error 28: timed out', new Request('POST', '/'), new Response(202, [], '{"status":')));

        $sent = $this->transport()->send($this->email());

        self::assertStringContainsString('202 Accepted', (string) $sent?->getDebug());
    }

    public function test_keeps_the_real_status_when_an_error_transfer_fails_mid_body(): void
    {
        if (! class_exists(ResponseException::class)) {
            self::markTestSkipped('Guzzle 8 attaches the received response as a ResponseException');
        }
        $this->responses->append(new ResponseException('cURL error 56: reset', new Request('POST', '/'), new Response(503)));

        try {
            $this->transport()->send($this->email());
            self::fail('The send was not refused');
        } catch (BrudcastException $exception) {
            self::assertSame(503, $exception->getStatusCode());
            self::assertSame('Brudcast responded with HTTP 503', $exception->getMessage());
        }
    }

    public function test_treats_a_body_that_cannot_be_read_as_empty(): void
    {
        $body = FnStream::decorate(Utils::streamFor('{}'), [
            '__toString' => static fn (): string => throw new RuntimeException('Connection reset'),
        ]);
        $this->responses->append(new Response(202, [], $body));

        $sent = $this->transport()->send($this->email());

        self::assertStringContainsString('202 Accepted', (string) $sent?->getDebug());
    }

    public function test_rejects_raw_messages(): void
    {
        $envelope = new Envelope(new Address('hello@acme.com'), [new Address('customer@example.com')]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The Brudcast transport requires a Symfony\\Component\\Mime\\Message, Symfony\\Component\\Mime\\RawMessage given.');

        try {
            $this->transport()->send(new RawMessage('Subject: Hi'), $envelope);
        } finally {
            self::assertNull($this->responses->getLastRequest());
        }
    }

    public function test_verify_accepts_the_key_when_the_empty_message_fails_validation(): void
    {
        $this->respond(422, [
            'status' => false,
            'message' => 'Validation failed',
            'errors' => [['path' => ['from'], 'message' => 'Required']],
        ]);

        $this->transport()->verify();

        self::assertSame('{}', (string) $this->lastRequest()->getBody());
        self::assertSame('application/json', $this->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function test_verify_accepts_an_empty_list_of_validation_issues(): void
    {
        $this->respond(422, ['status' => false, 'errors' => []]);

        $this->transport()->verify();

        self::assertCount(0, $this->responses);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unexpectedValidationResponses(): iterable
    {
        yield 'an HTML body' => ['<html>Unprocessable</html>', '<html>Unprocessable</html>'];
        yield 'an empty body' => ['', 'Brudcast responded with HTTP 422'];
        yield 'a JSON list' => ['[]', 'Brudcast responded with HTTP 422'];
        yield 'no errors' => ['{"status":false,"message":"Validation failed"}', 'Validation failed'];
        yield 'errors that are not a list' => ['{"status":false,"errors":{"from":"Required"}}', 'Brudcast responded with HTTP 422'];
        yield 'errors that are a string' => ['{"status":false,"errors":"Required"}', 'Brudcast responded with HTTP 422'];
        yield 'a successful status' => ['{"status":true,"errors":[]}', 'Brudcast responded with HTTP 422'];
        yield 'no status' => ['{"errors":[]}', 'Brudcast responded with HTTP 422'];
    }

    #[DataProvider('unexpectedValidationResponses')]
    public function test_verify_throws_when_a_422_is_not_the_services_validation_response(string $body, string $message): void
    {
        $this->responses->append(new Response(422, [], $body));

        try {
            $this->transport()->verify();
            self::fail('Expected a BrudcastException');
        } catch (BrudcastException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame($message, $exception->getMessage());
        }
    }

    public function test_verify_wraps_network_failures_in_a_transport_exception(): void
    {
        $this->responses->append(new ConnectException('Connection refused', new Request('POST', '/')));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach the Brudcast mailing service: Connection refused');

        $this->transport()->verify();
    }

    public function test_verify_throws_when_the_key_is_rejected(): void
    {
        $this->respond(401, ['status' => false, 'message' => 'Invalid sending key']);

        $this->expectException(BrudcastException::class);
        $this->expectExceptionMessage('Invalid sending key');

        $this->transport()->verify();
    }

    public function test_describes_itself_by_host(): void
    {
        self::assertSame('brudcast+api://mailing-service.brudcast.com', (string) $this->transport());
    }

    public function test_describes_itself_by_lowercase_host(): void
    {
        self::assertSame('brudcast+api://mail.example.com', (string) $this->transport('https://Mail.Example.COM/Prefix'));
    }
}

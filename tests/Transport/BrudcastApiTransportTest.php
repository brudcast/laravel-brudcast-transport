<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests\Transport;

use Brudcast\LaravelTransport\Exceptions\BrudcastException;
use Brudcast\LaravelTransport\Transport\BrudcastApiTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
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
        yield 'no scheme' => ['mailing-service.brudcast.com'];
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
        yield 'no scheme' => ['mailing-service:3000'];
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

    public function test_wraps_network_failures_in_a_transport_exception(): void
    {
        $this->responses->append(new ConnectException('Connection refused', new Request('POST', '/')));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach the Brudcast mailing service: Connection refused');

        $this->transport()->send($this->email());
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
        $this->respond(422, ['status' => false, 'message' => 'Validation failed']);

        $this->transport()->verify();

        self::assertSame('{}', (string) $this->lastRequest()->getBody());
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
}

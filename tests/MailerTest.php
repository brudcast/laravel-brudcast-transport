<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests;

use Brudcast\LaravelTransport\Transport\BrudcastApiTransport;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class MailerTest extends TestCase
{
    private function sendWelcome(string $mailer = 'brudcast'): void
    {
        Mail::mailer($mailer)->raw('Hi', function (Message $message): void {
            $message->from('hello@acme.com')->to('customer@example.com')->subject('Welcome');
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function mailer(array $config, string $name = 'custom'): void
    {
        config()->set("mail.mailers.{$name}", [
            'transport' => 'brudcast',
            'key' => self::SENDING_KEY,
            'guzzle' => ['handler' => HandlerStack::create($this->responses)],
            ...$config,
        ]);
    }

    private function accepted(): void
    {
        $this->responses->append(new Response(202, [], '{"status":true,"data":{"jobId":"job-47"}}'));
    }

    public function test_sends_through_the_http_api(): void
    {
        $this->responses->append(new Response(202, [], (string) json_encode([
            'status' => true,
            'message' => 'Email queued for delivery',
            'data' => ['jobId' => 'job-42'],
        ])));

        $sent = Mail::raw('Thanks for signing up.', function (Message $message): void {
            $message->from('hello@acme.com', 'Acme')->to('customer@example.com')->subject('Welcome');
        });

        self::assertNotNull($sent);
        self::assertSame('job-42@brudcast-mailing-service', $sent->getMessageId());
        self::assertSame('Bearer '.self::SENDING_KEY, $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_sends_embedded_images_with_the_content_id_the_html_references(): void
    {
        $this->responses->append(new Response(202, [], '{"status":true,"data":{"jobId":"job-43"}}'));

        Mail::send([], [], function (Message $message): void {
            $cid = $message->embedData('png-bytes', 'logo.png', 'image/png');
            $message->from('hello@acme.com')->to('customer@example.com')->subject('Welcome')
                ->html('<img src="'.$cid.'">');
        });

        $body = json_decode((string) $this->lastRequest()->getBody(), true);
        self::assertIsArray($body);
        self::assertIsString($body['html']);
        self::assertSame('<img src="cid:'.$body['attachments'][0]['cid'].'">', $body['html']);
    }

    public function test_reads_the_key_from_the_services_config(): void
    {
        config()->set('mail.mailers.fallback', ['transport' => 'brudcast']);
        config()->set('services.brudcast.key', 'bk_from_services');

        self::assertInstanceOf(BrudcastApiTransport::class, Mail::mailer('fallback')->getSymfonyTransport());
    }

    public function test_sends_to_the_endpoint_in_the_mailer_config(): void
    {
        config()->set('mail.mailers.staging', [
            'transport' => 'brudcast',
            'key' => self::SENDING_KEY,
            'endpoint' => 'https://mail.example.com',
            'guzzle' => ['handler' => HandlerStack::create($this->responses)],
        ]);
        $this->responses->append(new Response(202, [], '{"status":true,"data":{"jobId":"job-44"}}'));

        Mail::mailer('staging')->raw('Hi', function (Message $message): void {
            $message->from('hello@acme.com')->to('customer@example.com')->subject('Welcome');
        });

        self::assertSame('https://mail.example.com/api/v1/send', (string) $this->lastRequest()->getUri());
    }

    public function test_reads_the_endpoint_from_the_services_config(): void
    {
        config()->set('mail.mailers.fallback', ['transport' => 'brudcast', 'key' => self::SENDING_KEY]);
        config()->set('services.brudcast.endpoint', 'https://mail.example.com');

        self::assertSame(
            'brudcast+api://mail.example.com',
            (string) Mail::mailer('fallback')->getSymfonyTransport(),
        );
    }

    public function test_refuses_an_insecure_endpoint(): void
    {
        config()->set('mail.mailers.insecure', [
            'transport' => 'brudcast',
            'key' => self::SENDING_KEY,
            'endpoint' => 'http://mailing-service.brudcast.com',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https:// URL');

        Mail::mailer('insecure')->getSymfonyTransport();
    }

    public function test_allows_an_insecure_endpoint_when_the_mailer_opts_in(): void
    {
        config()->set('mail.mailers.docker', [
            'transport' => 'brudcast',
            'key' => self::SENDING_KEY,
            'endpoint' => 'http://mailing-service:3000',
            'insecure' => true,
            'guzzle' => ['handler' => HandlerStack::create($this->responses)],
        ]);
        $this->responses->append(new Response(202, [], '{"status":true,"data":{"jobId":"job-45"}}'));

        Mail::mailer('docker')->raw('Hi', function (Message $message): void {
            $message->from('hello@acme.com')->to('customer@example.com')->subject('Welcome');
        });

        self::assertSame('http://mailing-service:3000/api/v1/send', (string) $this->lastRequest()->getUri());
    }

    public function test_reads_the_insecure_option_from_an_environment_string(): void
    {
        config()->set('mail.mailers.docker', [
            'transport' => 'brudcast',
            'key' => self::SENDING_KEY,
            'endpoint' => 'http://mailing-service:3000',
            'insecure' => 'false',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https:// URL');

        Mail::mailer('docker')->getSymfonyTransport();
    }

    public function test_trims_the_key(): void
    {
        config()->set('mail.mailers.brudcast.key', ' '.self::SENDING_KEY."\n");
        $this->responses->append(new Response(202, [], '{"status":true,"data":{"jobId":"job-46"}}'));

        Mail::raw('Hi', function (Message $message): void {
            $message->from('hello@acme.com')->to('customer@example.com')->subject('Welcome');
        });

        self::assertSame('Bearer '.self::SENDING_KEY, $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_requires_a_key_that_is_not_blank(): void
    {
        config()->set('mail.mailers.blank', ['transport' => 'brudcast', 'key' => "  \n"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a key');

        Mail::mailer('blank')->getSymfonyTransport();
    }

    public function test_passes_the_default_timeouts_to_guzzle(): void
    {
        $this->accepted();

        $this->sendWelcome();

        $options = $this->responses->getLastOptions();
        self::assertSame(30.0, $options['timeout']);
        self::assertSame(10, $options['connect_timeout']);
    }

    public function test_passes_the_timeout_and_guzzle_options_to_guzzle(): void
    {
        $this->mailer([
            'timeout' => 5,
            'guzzle' => [
                'handler' => HandlerStack::create($this->responses),
                'connect_timeout' => 2,
                'proxy' => 'http://proxy.example.com:8080',
            ],
        ]);
        $this->accepted();

        $this->sendWelcome('custom');

        $options = $this->responses->getLastOptions();
        self::assertSame(5.0, $options['timeout']);
        self::assertSame(2, $options['connect_timeout']);
        self::assertSame('http://proxy.example.com:8080', $options['proxy']);
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function timeouts(): iterable
    {
        yield 'an integer' => [5, 5.0];
        yield 'a float' => [2.5, 2.5];
        yield 'a numeric string' => ['7', 7.0];
        yield 'a decimal string' => ['0.5', 0.5];
        yield 'a string with ASCII whitespace' => [" \t\x0B\x0C12\r\n", 12.0];
        yield 'the shortest timeout' => [0.001, 0.001];
        yield 'the longest timeout' => [2147483.647, 2147483.647];
        yield 'the longest timeout as a string' => ['2147483.647', 2147483.647];
    }

    #[DataProvider('timeouts')]
    public function test_reads_the_timeout_as_a_number_of_seconds(mixed $timeout, float $seconds): void
    {
        $this->mailer(['timeout' => $timeout]);
        $this->accepted();

        $this->sendWelcome('custom');

        self::assertSame($seconds, $this->responses->getLastOptions()['timeout']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'a negative number' => [-1];
        yield 'a negative string' => ['-5'];
        yield 'an empty string' => [''];
        yield 'a word' => ['thirty'];
        yield 'infinity' => [INF];
        yield 'not a number' => [NAN];
        yield 'a boolean' => [true];
        yield 'an array' => [[30]];
        yield 'an exponent' => ['1e3'];
        yield 'no integer part' => ['.5'];
        yield 'a trailing point' => ['5.'];
        yield 'a plus sign' => ['+5'];
        yield 'a hexadecimal string' => ['0x1A'];
        yield 'non-ASCII whitespace' => ["5\u{A0}"];
        yield 'a next-line byte' => ["5\x85"];
        yield 'too short' => [0.0001];
        yield 'too short as a string' => ['0.0009'];
        yield 'too long' => [2147483.648];
        yield 'too long as a string' => ['2147484'];
        yield 'huge' => [1e300];
    }

    #[DataProvider('invalidTimeouts')]
    public function test_refuses_a_timeout_that_is_not_a_positive_number(mixed $timeout): void
    {
        $this->mailer(['timeout' => $timeout]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Brudcast timeout must be a positive number of seconds no greater than 2147483.647.');

        Mail::mailer('custom')->getSymfonyTransport();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function enabledInsecureValues(): iterable
    {
        yield 'true' => [true];
        yield 'one' => [1];
        yield 'the string one' => ['1'];
        yield 'the string true' => ['true'];
        yield 'uppercase with whitespace' => [' TRUE '];
        yield 'on' => ['on'];
        yield 'yes' => ['Yes'];
    }

    #[DataProvider('enabledInsecureValues')]
    public function test_allows_an_insecure_endpoint_for_every_true_value(mixed $insecure): void
    {
        $this->mailer(['endpoint' => 'http://mailing-service:3000', 'insecure' => $insecure]);

        self::assertSame('brudcast+api://mailing-service', (string) Mail::mailer('custom')->getSymfonyTransport());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function disabledInsecureValues(): iterable
    {
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'two' => [2];
        yield 'the string false' => ['false'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
        yield 'another word' => ['enabled'];
        yield 'null' => [null];
    }

    #[DataProvider('disabledInsecureValues')]
    public function test_refuses_an_insecure_endpoint_for_every_other_value(mixed $insecure): void
    {
        $this->mailer(['endpoint' => 'http://mailing-service:3000', 'insecure' => $insecure]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an https:// URL');

        Mail::mailer('custom')->getSymfonyTransport();
    }

    public function test_uses_the_default_endpoint_when_the_endpoint_is_empty(): void
    {
        $this->mailer(['endpoint' => '']);
        $this->accepted();

        $this->sendWelcome('custom');

        self::assertSame('https://mailing-service.brudcast.com/api/v1/send', (string) $this->lastRequest()->getUri());
    }

    public function test_refuses_an_endpoint_that_is_not_a_string(): void
    {
        $this->mailer(['endpoint' => 8080]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Brudcast endpoint "8080" is not a valid URL.');

        Mail::mailer('custom')->getSymfonyTransport();
    }

    public function test_refuses_an_endpoint_with_credentials(): void
    {
        $this->mailer(['endpoint' => 'https://user:s3cret@mail.example.com']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Brudcast endpoint must not include a username or password.');

        Mail::mailer('custom')->getSymfonyTransport();
    }

    public function test_trims_ascii_whitespace_from_the_key(): void
    {
        config()->set('mail.mailers.brudcast.key', "\x0B\x0C\t ".self::SENDING_KEY." \r\n");
        $this->accepted();

        $this->sendWelcome();

        self::assertSame('Bearer '.self::SENDING_KEY, $this->lastRequest()->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysWithDisallowedCharacters(): iterable
    {
        yield 'a no-break space' => ["bk_secret\u{A0}"];
        yield 'an inner space' => ['bk_sec ret'];
        yield 'an inner line feed' => ["bk_sec\nret"];
        yield 'an inner carriage return' => ["bk_sec\rret"];
        yield 'a NUL byte' => ["bk_sec\0ret"];
        yield 'a delete character' => ["bk_sec\x7Fret"];
        yield 'a non-ASCII letter' => ['bk_sécret'];
    }

    #[DataProvider('keysWithDisallowedCharacters')]
    public function test_refuses_a_key_with_characters_other_than_visible_ascii_without_echoing_it(string $key): void
    {
        $this->mailer(['key' => $key]);

        try {
            Mail::mailer('custom')->getSymfonyTransport();
            self::fail('The key was accepted');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The Brudcast sending key contains characters that are not allowed.', $exception->getMessage());
            self::assertStringNotContainsString('sec', $exception->getMessage());
        }
        self::assertCount(0, $this->responses);
    }

    public function test_refuses_an_endpoint_without_echoing_its_credentials_or_query(): void
    {
        $this->mailer(['endpoint' => 'https://user:s3cret@mail example.com/api?token=abc#frag']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Brudcast endpoint "https://***@mail example.com/api" is not a valid URL.');

        Mail::mailer('custom')->getSymfonyTransport();
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableKeys(): iterable
    {
        yield 'blank' => ["  \n"];
        yield 'a number' => [12345];
        yield 'an array' => [['bk_array']];
    }

    #[DataProvider('unusableKeys')]
    public function test_falls_back_to_the_services_key_when_the_mailer_key_is_unusable(mixed $key): void
    {
        $this->mailer(['key' => $key]);
        config()->set('services.brudcast.key', ' bk_from_services ');
        $this->accepted();

        $this->sendWelcome('custom');

        self::assertSame('Bearer bk_from_services', $this->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_requires_a_services_key_that_is_a_string(): void
    {
        config()->set('mail.mailers.fallback', ['transport' => 'brudcast']);
        config()->set('services.brudcast.key', 12345);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a key');

        Mail::mailer('fallback')->getSymfonyTransport();
    }

    public function test_requires_credentials(): void
    {
        config()->set('mail.mailers.empty', ['transport' => 'brudcast', 'key' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a key');

        Mail::mailer('empty')->getSymfonyTransport();
    }
}

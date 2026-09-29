<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests;

use Brudcast\LaravelTransport\Transport\BrudcastApiTransport;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

final class MailerTest extends TestCase
{
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

    public function test_requires_credentials(): void
    {
        config()->set('mail.mailers.empty', ['transport' => 'brudcast', 'key' => null]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a key');

        Mail::mailer('empty')->getSymfonyTransport();
    }
}

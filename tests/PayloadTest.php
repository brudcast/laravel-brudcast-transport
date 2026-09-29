<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests;

use Brudcast\LaravelTransport\Payload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;

final class PayloadTest extends TestCase
{
    private function email(): Email
    {
        return (new Email)
            ->from(new Address('hello@acme.com', 'Acme'))
            ->to('customer@example.com')
            ->subject('Welcome to Acme')
            ->text('Thanks for signing up.');
    }

    public function test_maps_addresses_with_and_without_names(): void
    {
        $payload = Payload::fromEmail(
            $this->email()
                ->to(new Address('one@example.com', 'One'), 'two@example.com')
                ->cc('cc@example.com')
                ->bcc(new Address('bcc@example.com', 'Bcc'))
        );

        self::assertSame(['name' => 'Acme', 'address' => 'hello@acme.com'], $payload['from']);
        self::assertSame(
            [['name' => 'One', 'address' => 'one@example.com'], ['address' => 'two@example.com']],
            $payload['to'],
        );
        self::assertSame([['address' => 'cc@example.com']], $payload['cc']);
        self::assertSame([['name' => 'Bcc', 'address' => 'bcc@example.com']], $payload['bcc']);
    }

    public function test_sends_every_from_address_as_a_list(): void
    {
        $payload = Payload::fromEmail(
            $this->email()->from(new Address('hello@acme.com', 'Acme'), 'billing@acme.com')
        );

        self::assertSame(
            [['name' => 'Acme', 'address' => 'hello@acme.com'], ['address' => 'billing@acme.com']],
            $payload['from'],
        );
    }

    public function test_sends_a_single_reply_to_address_with_its_name(): void
    {
        $payload = Payload::fromEmail($this->email()->replyTo(new Address('support@acme.com', 'Support')));

        self::assertSame(['name' => 'Support', 'address' => 'support@acme.com'], $payload['replyTo']);
    }

    public function test_sends_every_reply_to_address_as_a_list(): void
    {
        $payload = Payload::fromEmail(
            $this->email()->replyTo(new Address('support@acme.com', 'Support'), 'other@acme.com')
        );

        self::assertSame(
            [['name' => 'Support', 'address' => 'support@acme.com'], ['address' => 'other@acme.com']],
            $payload['replyTo'],
        );
    }

    public function test_omits_empty_fields(): void
    {
        $payload = Payload::fromEmail($this->email());

        self::assertSame(['from', 'to', 'subject', 'text'], array_keys($payload));
    }

    public function test_maps_text_and_html_bodies(): void
    {
        $payload = Payload::fromEmail($this->email()->html('<p>Thanks for signing up.</p>'));

        self::assertSame('Thanks for signing up.', $payload['text']);
        self::assertSame('<p>Thanks for signing up.</p>', $payload['html']);
    }

    public function test_reads_bodies_given_as_streams(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '<p>From a stream</p>');

        $payload = Payload::fromEmail($this->email()->html($stream));

        self::assertSame('<p>From a stream</p>', $payload['html']);
    }

    public function test_encodes_attachments_as_base64(): void
    {
        $payload = Payload::fromEmail(
            $this->email()
                ->attach('a,b', 'report.csv', 'text/csv')
                ->attachFromPath(__DIR__.'/fixtures/hello.txt')
        );

        self::assertSame([
            [
                'filename' => 'report.csv',
                'content' => base64_encode('a,b'),
                'encoding' => 'base64',
                'contentType' => 'text/csv',
            ],
            [
                'filename' => 'hello.txt',
                'content' => base64_encode((string) file_get_contents(__DIR__.'/fixtures/hello.txt')),
                'encoding' => 'base64',
                'contentType' => 'text/plain',
            ],
        ], $payload['attachments']);
    }

    public function test_sends_parts_the_html_does_not_reference_as_regular_attachments(): void
    {
        $payload = Payload::fromEmail($this->email()->embed('png-bytes', 'logo.png', 'image/png'));

        self::assertIsArray($payload['attachments']);
        self::assertSame('logo.png', $payload['attachments'][0]['filename']);
        self::assertSame('image/png', $payload['attachments'][0]['contentType']);
        self::assertArrayNotHasKey('cid', $payload['attachments'][0]);
    }

    public function test_sends_a_part_referenced_by_content_id_with_that_cid(): void
    {
        $part = (new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline();
        $email = $this->email()->addPart($part)->html('<img src="cid:'.$part->getContentId().'">');

        $payload = Payload::fromEmail($email);

        self::assertIsArray($payload['attachments']);
        self::assertSame($part->getContentId(), $payload['attachments'][0]['cid']);
        self::assertSame('<img src="cid:'.$part->getContentId().'">', $payload['html']);
    }

    public function test_sends_a_part_referenced_by_name_with_that_name_as_its_cid(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'logo.png', 'image/png')
            ->embed('jpg-bytes', 'hero', 'image/jpeg')
            ->attach('a,b', 'report.csv', 'text/csv')
            ->html('<img src=cid:logo.png><td background="cid:hero">');

        $payload = Payload::fromEmail($email);

        self::assertIsArray($payload['attachments']);
        self::assertSame(
            ['logo.png', 'hero', null],
            array_map(static fn (array $attachment): ?string => $attachment['cid'] ?? null, $payload['attachments']),
        );
    }

    public function test_sends_a_part_whose_reference_is_not_a_valid_content_id_as_a_regular_attachment(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'my logo', 'image/png')
            ->embed('jpg-bytes', str_repeat('a', 256), 'image/jpeg')
            ->html('<img src="cid:my logo"><img src="cid:'.str_repeat('a', 256).'">');

        $payload = Payload::fromEmail($email);

        self::assertIsArray($payload['attachments']);
        self::assertSame(
            [null, null],
            array_map(static fn (array $attachment): ?string => $attachment['cid'] ?? null, $payload['attachments']),
        );
    }

    public function test_names_attachments_without_a_filename(): void
    {
        $payload = Payload::fromEmail($this->email()->addPart(new DataPart('data')));

        self::assertIsArray($payload['attachments']);
        self::assertSame('attachment-1', $payload['attachments'][0]['filename']);
        self::assertSame('application/octet-stream', $payload['attachments'][0]['contentType']);
    }

    public function test_forwards_custom_headers_only(): void
    {
        $email = $this->email()->cc('cc@example.com');
        $email->getHeaders()
            ->addTextHeader('X-Campaign', 'welcome')
            ->addTextHeader('X-Empty', '')
            ->addIdHeader('Message-ID', 'custom@acme.com');

        $payload = Payload::fromEmail($email);

        self::assertSame(['X-Campaign' => 'welcome'], $payload['headers']);
    }

    public function test_joins_repeated_headers_into_one_value(): void
    {
        $email = $this->email();
        $email->getHeaders()
            ->add(new TagHeader('welcome'))
            ->add(new TagHeader('onboarding'))
            ->addTextHeader('x-tag', 'autumn');

        $payload = Payload::fromEmail($email);

        self::assertSame(['X-Tag' => 'welcome, onboarding, autumn'], $payload['headers']);
    }

    public function test_sends_header_values_unencoded(): void
    {
        $email = $this->email();
        $email->getHeaders()
            ->addTextHeader('X-Campaign', 'Café à Zürich')
            ->add(new MetadataHeader('customer', 'Jürgen'));

        $payload = Payload::fromEmail($email);

        self::assertSame(['X-Campaign' => 'Café à Zürich', 'X-Metadata-customer' => 'Jürgen'], $payload['headers']);
    }

    public function test_sends_structured_headers_as_their_header_body(): void
    {
        $email = $this->email();
        $email->getHeaders()
            ->addIdHeader('In-Reply-To', 'thread-1@acme.com')
            ->addMailboxHeader('X-Original-Sender', new Address('support@acme.com', 'Acme Support'));

        $payload = Payload::fromEmail($email);

        self::assertSame([
            'In-Reply-To' => '<thread-1@acme.com>',
            'X-Original-Sender' => 'Acme Support <support@acme.com>',
        ], $payload['headers']);
    }

    public function test_sends_the_priority_as_its_header(): void
    {
        $payload = Payload::fromEmail($this->email()->priority(Email::PRIORITY_HIGHEST));

        self::assertSame(['X-Priority' => '1 (Highest)'], $payload['headers']);
    }

    public function test_replaces_line_breaks_in_header_values(): void
    {
        $email = $this->email();
        $email->getHeaders()->addTextHeader('X-Campaign', "welcome\r\nBcc: attacker@example.com\nX-Other: 1");

        $payload = Payload::fromEmail($email);

        self::assertSame(['X-Campaign' => 'welcome Bcc: attacker@example.com X-Other: 1'], $payload['headers']);
    }

    public function test_rejects_an_invalid_header_name(): void
    {
        $email = $this->email();
        // Symfony Mime 7.4+ rejects the name itself; older versions accept it
        $email->getHeaders()->add(new class implements HeaderInterface
        {
            public function setBody(mixed $body): void {}

            public function getBody(): string
            {
                return 'attacker@example.com';
            }

            public function setCharset(string $charset): void {}

            public function getCharset(): string
            {
                return 'utf-8';
            }

            public function setLanguage(string $lang): void {}

            public function getLanguage(): ?string
            {
                return null;
            }

            public function getName(): string
            {
                return "X-Campaign: welcome\r\nBcc";
            }

            public function setMaxLineLength(int $lineLength): void {}

            public function getMaxLineLength(): int
            {
                return 76;
            }

            public function toString(): string
            {
                return $this->getName().': '.$this->getBody();
            }

            public function getBodyAsString(): string
            {
                return $this->getBody();
            }
        });

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('is not valid');

        Payload::fromEmail($email);
    }

    public function test_sends_to_the_envelope_recipients_that_are_not_copied(): void
    {
        $email = $this->email()
            ->to(new Address('customer@example.com', 'Customer'))
            ->cc('cc@example.com')
            ->bcc('bcc@example.com');
        $envelope = new Envelope(new Address('hello@acme.com'), [
            new Address('CUSTOMER@example.com'),
            new Address('cc@example.com'),
            new Address('bcc@example.com'),
            new Address('extra@example.com'),
        ]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame(
            [['name' => 'Customer', 'address' => 'customer@example.com'], ['address' => 'extra@example.com']],
            $payload['to'],
        );
        self::assertSame([['address' => 'cc@example.com']], $payload['cc']);
        self::assertSame([['address' => 'bcc@example.com']], $payload['bcc']);
    }

    public function test_leaves_out_to_addresses_the_envelope_drops(): void
    {
        $email = $this->email()->to('customer@example.com', 'other@example.com');
        $envelope = new Envelope(new Address('hello@acme.com'), [new Address('other@example.com')]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame([['address' => 'other@example.com']], $payload['to']);
    }

    public function test_omits_a_missing_sender(): void
    {
        $payload = Payload::fromEmail((new Email)->to('customer@example.com')->text('Hi'));

        self::assertArrayNotHasKey('from', $payload);
    }
}

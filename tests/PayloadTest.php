<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Tests;

use Brudcast\LaravelTransport\Payload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\IdentificationHeader;
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

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string|null>
     */
    private static function cids(array $payload): array
    {
        self::assertIsArray($payload['attachments']);

        return array_values(array_map(static fn (array $attachment): ?string => $attachment['cid'] ?? null, $payload['attachments']));
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

    public function test_sends_international_domains_in_their_ascii_form(): void
    {
        $payload = Payload::fromEmail(
            $this->email()
                ->from(new Address('hello@bücher.example', 'Bücher'))
                ->to('kunde@bücher.example')
                ->replyTo('support@bücher.example')
        );

        self::assertSame(['name' => 'Bücher', 'address' => 'hello@xn--bcher-kva.example'], $payload['from']);
        self::assertSame([['address' => 'kunde@xn--bcher-kva.example']], $payload['to']);
        self::assertSame(['address' => 'support@xn--bcher-kva.example'], $payload['replyTo']);
    }

    public function test_sends_each_recipient_once(): void
    {
        $payload = Payload::fromEmail(
            $this->email()
                ->to(new Address('customer@example.com', 'Customer'), 'CUSTOMER@example.com', 'other@example.com')
                ->cc('cc@example.com', 'CC@EXAMPLE.COM')
                ->bcc('bcc@example.com', 'Bcc@Example.com')
        );

        self::assertSame(
            [['name' => 'Customer', 'address' => 'customer@example.com'], ['address' => 'other@example.com']],
            $payload['to'],
        );
        self::assertSame([['address' => 'cc@example.com']], $payload['cc']);
        self::assertSame([['address' => 'bcc@example.com']], $payload['bcc']);
    }

    public function test_compares_international_recipients_by_their_ascii_form(): void
    {
        $payload = Payload::fromEmail($this->email()->to('kunde@bücher.example', 'kunde@XN--BCHER-KVA.example'));

        self::assertSame([['address' => 'kunde@xn--bcher-kva.example']], $payload['to']);
    }

    public function test_sends_an_address_whose_domain_cannot_be_converted_as_given(): void
    {
        $payload = Payload::fromEmail(
            $this->email()
                ->from(new Address('hello@ex_ample.cöm', 'Hello'))
                ->to('one@ex_ample.cöm', 'two@ex_ample.cöm', 'ONE@EX_AMPLE.CÖM')
        );

        self::assertSame(['name' => 'Hello', 'address' => 'hello@ex_ample.cöm'], $payload['from']);
        self::assertSame([['address' => 'one@ex_ample.cöm'], ['address' => 'two@ex_ample.cöm']], $payload['to']);
    }

    public function test_matches_envelope_recipients_whose_domain_cannot_be_converted(): void
    {
        $email = $this->email()->to('one@ex_ample.cöm')->cc('two@ex_ample.cöm');
        $envelope = new Envelope(new Address('hello@acme.com'), [new Address('two@ex_ample.cöm'), new Address('one@ex_ample.cöm')]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame([['address' => 'one@ex_ample.cöm']], $payload['to']);
        self::assertSame([['address' => 'two@ex_ample.cöm']], $payload['cc']);
    }

    public function test_compares_non_ascii_local_parts_with_unicode_lowercasing(): void
    {
        $payload = Payload::fromEmail($this->email()->to('ÄNNE@example.com', 'änne@example.com', 'ΣΟΦΙΑ@example.com', 'σοφια@example.com'));

        self::assertSame([['address' => 'ÄNNE@example.com'], ['address' => 'ΣΟΦΙΑ@example.com']], $payload['to']);
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

    public function test_matches_a_content_id_without_its_angle_brackets(): void
    {
        $part = (new DataPart('png-bytes', 'logo.png', 'image/png'))->asInline()->setContentId('<logo@acme.com>');
        $email = $this->email()->addPart($part)->html('<img src="cid:logo@acme.com">');

        $payload = Payload::fromEmail($email);

        self::assertSame(['logo@acme.com'], self::cids($payload));
    }

    public function test_matches_a_cid_in_a_background_attribute(): void
    {
        $email = $this->email()
            ->embed('jpg-bytes', 'hero', 'image/jpeg')
            ->html('<table><td background="cid:hero">Welcome</td></table>');

        self::assertSame(['hero'], self::cids(Payload::fromEmail($email)));
    }

    public function test_matches_an_unquoted_cid_reference(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'logo.png', 'image/png')
            ->html('<img alt="" src=cid:logo.png>');

        self::assertSame(['logo.png'], self::cids(Payload::fromEmail($email)));
    }

    public function test_matches_a_cid_reference_of_zero(): void
    {
        $email = $this->email()
            ->embed('png-bytes', '0', 'image/png')
            ->embed('jpg-bytes', '1', 'image/jpeg')
            ->html('<img src="cid:0"><img src=cid:1>');

        self::assertSame(['0', '1'], self::cids(Payload::fromEmail($email)));
    }

    public function test_matches_an_unquoted_cid_of_zero(): void
    {
        $email = $this->email()->embed('png-bytes', '0', 'image/png')->html('<img src=cid:0>');

        self::assertSame(['0'], self::cids(Payload::fromEmail($email)));
    }

    public function test_takes_the_last_cid_reference_in_a_tag_as_symfony_does(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'first', 'image/png')
            ->embed('jpg-bytes', 'second', 'image/jpeg')
            ->html('<img src="cid:first" data-src="cid:second">');

        self::assertSame([null, 'second'], self::cids(Payload::fromEmail($email)));
    }

    public function test_matches_a_background_in_an_image_tag_without_a_cid_source(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'logo', 'image/png')
            ->html('<img src="logo.png" background="cid:logo">');

        self::assertSame(['logo'], self::cids(Payload::fromEmail($email)));
    }

    public function test_ignores_a_cid_attribute_outside_a_tag(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'logo', 'image/png')
            ->html('<p>src="cid:logo"</p><img> src="cid:logo"');

        self::assertSame([null], self::cids(Payload::fromEmail($email)));
    }

    /**
     * The same HTML as the Nodemailer transport's scan tests, which must give the same result
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function unicodeWhitespaceReferences(): iterable
    {
        yield 'a non-breaking space after the tag name' => ["<img\u{A0}src=\"cid:logo\">", null];
        yield 'non-breaking spaces around the equals sign' => ["<img src\u{A0}=\u{A0}\"cid:logo\">", null];
        yield 'a non-breaking space ending an unquoted cid' => ["<img src=cid:logo\u{A0}x>", null];
        yield 'an ASCII space ending an unquoted cid' => ['<img src=cid:logo x>', 'logo'];
    }

    #[DataProvider('unicodeWhitespaceReferences')]
    public function test_treats_only_ascii_whitespace_as_whitespace_in_a_reference(string $html, ?string $cid): void
    {
        $email = $this->email()->embed('png-bytes', 'logo', 'image/png')->html($html);

        self::assertSame([$cid], self::cids(Payload::fromEmail($email)));
    }

    public function test_a_later_reference_too_long_for_the_service_hides_an_earlier_one(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'good', 'image/png')
            ->html('<img src="cid:good" src="cid:'.str_repeat('x', 300).'">');

        self::assertSame([null], self::cids(Payload::fromEmail($email)));
    }

    public function test_matches_a_cid_after_up_to_4000_characters_of_attributes(): void
    {
        $email = $this->email()
            ->embed('png-bytes', 'near', 'image/png')
            ->embed('jpg-bytes', 'far', 'image/jpeg')
            ->html(
                '<img   '.str_repeat('a', 4000).'src="cid:near">'
                .'<img   '.str_repeat('a', 4001).'src="cid:far">'
            );

        self::assertSame(['near', null], self::cids(Payload::fromEmail($email)));
    }

    public function test_matches_a_cid_of_255_multibyte_characters(): void
    {
        $cid = str_repeat('語', 255);
        $email = $this->email()->embed('png-bytes', $cid, 'image/png')->html('<p background='.'cid:'.$cid.'>');

        self::assertSame([$cid], self::cids(Payload::fromEmail($email)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileHtml(): iterable
    {
        yield 'unclosed tags' => [str_repeat('<a ', 100000).'background=cid:logo'];
        yield 'unclosed tags with backgrounds' => [str_repeat('<a background ', 22000).'background=cid:logo'];
        yield 'unclosed image tags' => [str_repeat('<img src ', 34000).'src=cid:logo'];
        yield 'a quote that never closes' => ['<img src="cid:'.str_repeat('x', 300000)];
        yield 'quotes that never close' => [str_repeat('<img src="cid:x', 20000)];
        yield 'unquoted references without an end' => [str_repeat('src=cid:', 38000)];
        yield 'a long run of whitespace' => ['<a'.str_repeat(' ', 300000).'background=cid:logo'];
    }

    #[DataProvider('hostileHtml')]
    public function test_finds_cid_references_in_linear_time(string $html): void
    {
        self::assertGreaterThanOrEqual(300000, strlen($html));
        $email = $this->email()->embed('png-bytes', 'logo', 'image/png')->html($html);

        $started = microtime(true);
        Payload::fromEmail($email);

        self::assertLessThan(1.0, microtime(true) - $started);
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function validContentIds(): iterable
    {
        yield 'one character' => ['a'];
        yield '255 characters' => [str_repeat('a', 255)];
        yield '255 two-byte characters' => [str_repeat('é', 255)];
        yield '127 characters outside the BMP' => [str_repeat('😀', 127)];
        yield 'a next line character' => ["logo\u{85}png"];
    }

    #[DataProvider('validContentIds')]
    public function test_sends_a_valid_content_id(string $cid): void
    {
        $email = $this->email()->embed('png-bytes', $cid, 'image/png')->html('<img src="cid:'.$cid.'">');

        self::assertSame([$cid], self::cids(Payload::fromEmail($email)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidContentIds(): iterable
    {
        yield 'a space' => ['my logo'];
        yield 'a tab' => ["my\tlogo"];
        yield 'a vertical tab' => ["my\x0Blogo"];
        yield 'a no-break space' => ["my\u{A0}logo"];
        yield 'an em space' => ["my\u{2003}logo"];
        yield 'an ideographic space' => ["my\u{3000}logo"];
        yield 'a byte order mark' => ["\u{FEFF}logo"];
        yield 'an angle bracket' => ['my>logo'];
        yield '256 characters' => [str_repeat('a', 256)];
        yield '128 characters outside the BMP' => [str_repeat('😀', 128)];
        yield 'invalid UTF-8' => ["logo\xFF"];
    }

    #[DataProvider('invalidContentIds')]
    public function test_sends_a_part_with_an_invalid_content_id_as_a_regular_attachment(string $cid): void
    {
        $email = $this->email()->embed('png-bytes', $cid, 'image/png')->html('<img src="cid:'.$cid.'">');

        self::assertSame([null], self::cids(Payload::fromEmail($email)));
    }

    public function test_names_attachments_without_a_filename(): void
    {
        $payload = Payload::fromEmail(
            $this->email()
                ->addPart(new DataPart('data'))
                ->addPart(new DataPart('png-bytes', null, 'image/png'))
                ->addPart(new DataPart('bytes', null, 'x-unknown/type'))
        );

        self::assertIsArray($payload['attachments']);
        self::assertSame(
            [
                ['attachment-1.bin', 'application/octet-stream'],
                ['attachment-2.png', 'image/png'],
                ['attachment-3.bin', 'x-unknown/type'],
            ],
            array_map(
                static fn (array $attachment): array => [$attachment['filename'], $attachment['contentType']],
                $payload['attachments'],
            ),
        );
    }

    public function test_names_an_inline_part_without_a_filename_after_its_content_type(): void
    {
        $part = (new DataPart('png-bytes', null, 'image/png'))->asInline();

        $payload = Payload::fromEmail($this->email()->addPart($part)->html('<img src="cid:'.$part->getContentId().'">'));

        self::assertIsArray($payload['attachments']);
        self::assertSame('attachment-1.png', $payload['attachments'][0]['filename']);
        self::assertSame($part->getContentId(), $payload['attachments'][0]['cid']);
    }

    public function test_keeps_a_filename_of_zero(): void
    {
        $payload = Payload::fromEmail($this->email()->attach('data', '0', 'text/plain'));

        self::assertIsArray($payload['attachments']);
        self::assertSame('0', $payload['attachments'][0]['filename']);
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function contentTypes(): iterable
    {
        yield 'guessed from the extension' => ['report.pdf', null, 'application/pdf'];
        yield 'guessed from an uppercase extension' => ['REPORT.PDF', null, 'application/pdf'];
        yield 'an unknown extension' => ['data.zzzz', null, 'application/octet-stream'];
        yield 'no extension' => ['README', null, 'application/octet-stream'];
        yield 'a given type' => ['report.pdf', 'text/plain', 'text/plain'];
    }

    #[DataProvider('contentTypes')]
    public function test_guesses_a_missing_content_type_from_the_filename(string $filename, ?string $type, string $expected): void
    {
        $payload = Payload::fromEmail($this->email()->attach('bytes', $filename, $type));

        self::assertIsArray($payload['attachments']);
        self::assertSame($expected, $payload['attachments'][0]['contentType']);
    }

    public function test_rejects_an_empty_attachment(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The attachment "empty.txt" is empty.');

        Payload::fromEmail($this->email()->attach('', 'empty.txt', 'text/plain'));
    }

    public function test_names_an_empty_attachment_without_a_filename_in_the_error(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The attachment "attachment-2.png" is empty.');

        Payload::fromEmail($this->email()->attach('a,b', 'report.csv')->addPart(new DataPart('', null, 'image/png')));
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

    public function test_keeps_the_order_each_header_first_appears_in(): void
    {
        $email = $this->email();
        $email->getHeaders()
            ->addTextHeader('X-Second', 'b')
            ->addTextHeader('X-First', 'a')
            ->addTextHeader('x-second', 'c');

        $payload = Payload::fromEmail($email);

        self::assertSame(['X-Second' => 'b, c', 'X-First' => 'a'], $payload['headers']);
    }

    public function test_joins_repeated_threading_headers_with_a_space(): void
    {
        $email = $this->email();
        $headers = $email->getHeaders();
        $headers
            ->addIdHeader('In-Reply-To', 'thread-2@acme.com')
            ->addIdHeader('References', 'thread-1@acme.com');
        // Symfony allows one of each, so a second is added past its check, as another client may send it
        $property = new ReflectionProperty(Headers::class, 'headers');
        $all = $property->getValue($headers);
        self::assertIsArray($all);
        $all['in-reply-to'][] = new IdentificationHeader('in-reply-to', 'thread-3@acme.com');
        $all['references'][] = new IdentificationHeader('REFERENCES', 'thread-2@acme.com');
        $property->setValue($headers, $all);

        $payload = Payload::fromEmail($email);

        self::assertSame([
            'In-Reply-To' => '<thread-2@acme.com> <thread-3@acme.com>',
            'References' => '<thread-1@acme.com> <thread-2@acme.com>',
        ], $payload['headers']);
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

    public function test_trims_only_spaces_and_tabs_from_header_values(): void
    {
        $email = $this->email();
        $email->getHeaders()
            ->addTextHeader('X-Spaces', " \t welcome \t ")
            ->addTextHeader('X-Line-Break', "welcome\r\n")
            ->addTextHeader('X-No-Break-Space', "\u{A0}welcome\u{A0}")
            ->addTextHeader('X-Blank', " \t\r\n ");

        $payload = Payload::fromEmail($email);

        self::assertSame([
            'X-Spaces' => 'welcome',
            'X-Line-Break' => 'welcome',
            'X-No-Break-Space' => "\u{A0}welcome\u{A0}",
        ], $payload['headers']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidHeaderNames(): iterable
    {
        yield 'a line break and a colon' => ["X-Campaign: welcome\r\nBcc"];
        yield 'a reserved name with a trailing line feed' => ["Bcc\n"];
        yield 'a custom name with a trailing line feed' => ["X-Campaign\n"];
    }

    #[DataProvider('invalidHeaderNames')]
    public function test_rejects_an_invalid_header_name(string $name): void
    {
        $email = $this->email();
        // Symfony Mime 7.4+ rejects such names itself; older versions accept them
        $email->getHeaders()->add(new class($name) implements HeaderInterface
        {
            public function __construct(private readonly string $name) {}

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
                return $this->name;
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

    public function test_keeps_a_recipient_in_to_that_is_also_copied(): void
    {
        $email = $this->email()
            ->to(new Address('customer@example.com', 'Customer'))
            ->cc('customer@example.com')
            ->bcc('CUSTOMER@example.com');
        $envelope = new Envelope(new Address('hello@acme.com'), [new Address('customer@example.com')]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame([['name' => 'Customer', 'address' => 'customer@example.com']], $payload['to']);
        self::assertSame([['address' => 'customer@example.com']], $payload['cc']);
        self::assertSame([['address' => 'CUSTOMER@example.com']], $payload['bcc']);
    }

    public function test_leaves_out_copies_the_envelope_drops(): void
    {
        $email = $this->email()
            ->cc(new Address('kept@example.com', 'Kept'), 'dropped@example.com')
            ->bcc('dropped-bcc@example.com');
        $envelope = new Envelope(new Address('hello@acme.com'), [
            new Address('customer@example.com'),
            new Address('KEPT@example.com'),
        ]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame([['name' => 'Kept', 'address' => 'kept@example.com']], $payload['cc']);
        self::assertArrayNotHasKey('bcc', $payload);
    }

    public function test_sends_to_the_envelope_recipients_in_envelope_order_once_each(): void
    {
        $email = $this->email()->to(new Address('second@example.com', 'Second'), 'first@example.com');
        $envelope = new Envelope(new Address('hello@acme.com'), [
            new Address('first@example.com'),
            new Address('extra@example.com'),
            new Address('SECOND@example.com'),
            new Address('Extra@example.com'),
        ]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame([
            ['address' => 'first@example.com'],
            ['address' => 'extra@example.com'],
            ['name' => 'Second', 'address' => 'second@example.com'],
        ], $payload['to']);
    }

    public function test_matches_envelope_recipients_by_their_ascii_form(): void
    {
        $email = $this->email()->to(new Address('kunde@bücher.example', 'Kunde'));
        $envelope = new Envelope(new Address('hello@acme.com'), [new Address('kunde@xn--bcher-kva.example')]);

        $payload = Payload::fromEmail($email, $envelope);

        self::assertSame([['name' => 'Kunde', 'address' => 'kunde@xn--bcher-kva.example']], $payload['to']);
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

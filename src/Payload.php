<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\Part\DataPart;

final class Payload
{
    /**
     * Headers the mailing service sets itself, or reads from the dedicated payload fields
     */
    private const RESERVED_HEADERS = [
        'bcc',
        'cc',
        'content-transfer-encoding',
        'content-type',
        'date',
        'from',
        'message-id',
        'mime-version',
        'reply-to',
        'return-path',
        'sender',
        'subject',
        'to',
        'x-brudcast-job-id',
    ];

    /**
     * A header name is printable ASCII other than the colon (RFC 5322 section 3.6.8)
     */
    private const HEADER_NAME_PATTERN = '/^[!-9;-~]+$/D';

    /**
     * Headers whose repeated values are joined with a space, since each value is a list of message
     * IDs rather than a phrase
     */
    private const SPACE_JOINED_HEADERS = ['in-reply-to', 'references'];

    /**
     * A character the mailing service rejects in a content ID: an angle bracket or whitespace, as
     * JavaScript's `\s` matches it
     */
    private const CONTENT_ID_FORBIDDEN_PATTERN = '/[<>\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u';

    /**
     * The longest content ID the mailing service accepts, in UTF-16 code units as it counts them
     */
    private const CONTENT_ID_MAX_LENGTH = 255;

    private const DEFAULT_CONTENT_TYPE = 'application/octet-stream';

    /**
     * A `src` or `background` attribute whose value is a `cid:` reference, quoted or not, found at
     * every offset so none hides inside another. The ID is at most 765 bytes, the most that 255
     * UTF-16 code units (the longest content ID the service accepts) take in UTF-8.
     */
    private const CONTENT_ID_ATTRIBUTE_PATTERN = '/(?=((src|background)\s*=\s*(?:([\'"])cid:(.{1,765}?)\3|cid:([^>\s]{1,765}+)(?=[>\s]|$))))/iD';

    /**
     * The start of a tag: its name, then the whitespace before its attributes
     */
    private const TAG_START_PATTERN = '/<(\w+)(\s+)/';

    /**
     * The most characters, after the whitespace that follows a tag's name, that may come before
     * a `cid:` attribute in the same tag
     */
    private const MAX_ATTRIBUTES_BEFORE_REFERENCE = 4000;

    /**
     * Build the body of a send request to the Brudcast HTTP API
     *
     * With an envelope, its recipients decide who receives the message, as in Symfony's API
     * transports: `to` is every envelope recipient in the To header, plus any that appear in none of
     * To, Cc or Bcc, and `cc` and `bcc` are the Cc and Bcc addresses that are envelope recipients.
     * Without one, the headers are used as they are. Each list is sent once per address, compared
     * without regard to case.
     *
     * @return array<string, mixed>
     *
     * @throws TransportException When a custom header has an invalid name, or an attachment is empty
     */
    public static function fromEmail(Email $email, ?Envelope $envelope = null): array
    {
        $html = self::body($email->getHtmlBody());
        [$to, $cc, $bcc] = $envelope !== null
            ? self::envelopeRecipients($email, $envelope)
            : [$email->getTo(), $email->getCc(), $email->getBcc()];

        $payload = [
            'from' => self::addressOrList($email->getFrom()),
            'to' => self::addressList(self::unique($to)),
            'cc' => self::addressList(self::unique($cc)),
            'bcc' => self::addressList(self::unique($bcc)),
            'replyTo' => self::addressOrList($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'text' => self::body($email->getTextBody()),
            'html' => $html,
            'attachments' => self::attachments($email->getAttachments(), self::contentIdReferences($html)),
            'headers' => self::headers($email),
        ];

        return array_filter($payload, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A single address as one object and several as a list, for the fields the service accepts
     * either way, so a message with one address is sent exactly as before lists were accepted
     *
     * @param  Address[]  $addresses
     * @return array{name?: string, address: string}|list<array{name?: string, address: string}>|null
     */
    private static function addressOrList(array $addresses): ?array
    {
        return count($addresses) > 1 ? self::addressList($addresses) : self::address($addresses[0] ?? null);
    }

    /**
     * @return array{name?: string, address: string}|null
     */
    private static function address(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return $address->getName() !== ''
            ? ['name' => $address->getName(), 'address' => self::encoded($address)]
            : ['address' => self::encoded($address)];
    }

    /**
     * The address with its domain in ASCII (punycode) form, as Symfony's own transports send it, or
     * as given when the domain cannot be converted, so the service decides whether it is valid
     */
    private static function encoded(Address $address): string
    {
        $encoded = $address->getEncodedAddress();

        // Symfony leaves only the `@` when IDN conversion fails
        return str_ends_with($encoded, '@') ? $address->getAddress() : $encoded;
    }

    /**
     * @param  Address[]  $addresses
     * @return list<array{name?: string, address: string}>|null
     */
    private static function addressList(array $addresses): ?array
    {
        $list = array_values(array_filter(array_map(self::address(...), $addresses)));

        return $list !== [] ? $list : null;
    }

    /**
     * The To, Cc and Bcc recipients the envelope keeps
     *
     * `to` follows the envelope's order: a recipient in the To header is named as it is there, since
     * the envelope keeps only addresses, and one in none of the headers is added as a bare address.
     * A recipient in To and also in Cc or Bcc stays in `to`.
     *
     * @return array{list<Address>, list<Address>, list<Address>}
     */
    private static function envelopeRecipients(Email $email, Envelope $envelope): array
    {
        $recipients = self::keyed($envelope->getRecipients());
        $named = self::keyed($email->getTo());
        $copied = self::keyed([...$email->getCc(), ...$email->getBcc()]);

        $to = [];
        foreach ($recipients as $key => $recipient) {
            if (isset($named[$key])) {
                $to[] = $named[$key];
            } elseif (! isset($copied[$key])) {
                $to[] = $recipient;
            }
        }
        $kept = static fn (Address $address): bool => isset($recipients[self::key($address)]);

        return [
            $to,
            array_values(array_filter($email->getCc(), $kept)),
            array_values(array_filter($email->getBcc(), $kept)),
        ];
    }

    /**
     * @param  Address[]  $addresses
     * @return list<Address>
     */
    private static function unique(array $addresses): array
    {
        return array_values(self::keyed($addresses));
    }

    /**
     * The addresses by their key, keeping the first of any that share one
     *
     * @param  Address[]  $addresses
     * @return array<string, Address>
     */
    private static function keyed(array $addresses): array
    {
        $keyed = [];
        foreach ($addresses as $address) {
            $keyed[self::key($address)] ??= $address;
        }

        return $keyed;
    }

    private static function key(Address $address): string
    {
        // Unicode lowercasing, as JavaScript's toLowerCase() does it, for a non-ASCII local part
        return mb_strtolower(self::encoded($address), 'UTF-8');
    }

    /**
     * @param  resource|string|null  $body
     */
    private static function body(mixed $body): ?string
    {
        if ($body === null || is_string($body)) {
            return $body;
        }
        rewind($body);

        return (string) stream_get_contents($body);
    }

    /**
     * A part the HTML references is sent with that reference as its cid, so the service delivers
     * it inline and the HTML's `cid:` link resolves unchanged
     *
     * @param  DataPart[]  $parts
     * @param  list<string>  $references
     * @return list<array{filename: string, content: string, encoding: 'base64', contentType: string, cid?: string}>|null
     *
     * @throws TransportException When an attachment is empty
     */
    private static function attachments(array $parts, array $references): ?array
    {
        $attachments = [];
        foreach (array_values($parts) as $index => $part) {
            $filename = self::filename($part, $index + 1);
            $content = $part->getBody();
            if ($content === '') {
                throw new TransportException(sprintf('The attachment "%s" is empty.', $filename));
            }
            $attachment = [
                'filename' => $filename,
                'content' => base64_encode($content),
                'encoding' => 'base64',
                'contentType' => self::contentType($part, $filename),
            ];
            $cid = self::referenceTo($part, $references);
            if ($cid !== null) {
                $attachment['cid'] = $cid;
            }
            $attachments[] = $attachment;
        }

        return $attachments !== [] ? $attachments : null;
    }

    /**
     * A part without a filename is named after its position and content type, such as
     * `attachment-1.png`, in the form Nodemailer uses. The extension comes from Symfony's MIME
     * types, so it can differ from Nodemailer's for some types, such as `jpg` for `image/jpeg`.
     */
    private static function filename(DataPart $part, int $position): string
    {
        $filename = $part->getFilename();
        if (is_string($filename) && $filename !== '') {
            return $filename;
        }

        return 'attachment-'.$position.'.'.(MimeTypes::getDefault()->getExtensions($part->getContentType())[0] ?? 'bin');
    }

    /**
     * Symfony falls back to `application/octet-stream` for a part added without a content type, so
     * the type is then guessed from the filename's extension
     */
    private static function contentType(DataPart $part, string $filename): string
    {
        $type = $part->getContentType();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if ($type !== self::DEFAULT_CONTENT_TYPE || $extension === '') {
            return $type;
        }

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? $type;
    }

    /**
     * The `cid:` names in image sources and backgrounds, found the way Symfony finds them when it
     * builds the MIME message, quoted references first
     *
     * This gives the same result as matching
     * `/<(?:img\s+[^>]{0,4000}src|\w+\s+[^>]{0,4000}background)\s*=\s*(?:(['"])cid:(.{1,765}?)\1|cid:([^>\s]{1,765})(?=[>\s]|$))/i`,
     * which only differs from Symfony's pattern for over 4000 characters of attributes before the
     * reference, or an ID too long for the service. A regular expression retries the attributes
     * from every `<`, which takes seconds on hostile HTML such as `<a <a <a …`, so instead each
     * attribute is found once, and each tag takes the last one within its reach.
     *
     * @return list<string>
     */
    private static function contentIdReferences(?string $html): array
    {
        if ($html === null) {
            return [];
        }

        $attributes = ['src' => [], 'background' => []];
        $found = false;
        $offset = 0;
        while (preg_match(self::CONTENT_ID_ATTRIBUTE_PATTERN, $html, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $offset) === 1) {
            $start = $match[0][1];
            $attributes[strtolower((string) $match[2][0])][] = [
                $start,
                $start + strlen((string) $match[1][0]),
                $match[4][0] !== null,
                (string) ($match[4][0] ?? $match[5][0]),
            ];
            $found = true;
            $offset = $start + 1;
        }
        if (! $found) {
            return [];
        }

        $quoted = [];
        $unquoted = [];
        $tagEnd = -1;
        $offset = 0;
        while (preg_match(self::TAG_START_PATTERN, $html, $tag, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [[$opening, $start], [$name]] = $tag;
            $offset = $start + strlen($opening);
            if ($tagEnd < $start) {
                $tagEnd = strpos($html, '>', $start);
                $tagEnd = $tagEnd !== false ? $tagEnd : strlen($html);
            }
            // The attribute may start after the first whitespace character that follows the tag's
            // name, until the tag's `>` or the end of its reach
            $first = $start + strlen($name) + 2;
            $last = min($tagEnd, $offset + self::MAX_ATTRIBUTES_BEFORE_REFERENCE);
            $attribute = (strtolower($name) === 'img' ? self::lastAttribute($attributes['src'], $first, $last) : null)
                ?? self::lastAttribute($attributes['background'], $first, $last);
            if ($attribute !== null) {
                [, $attributeEnd, $isQuoted, $cid] = $attribute;
                $isQuoted ? $quoted[] = $cid : $unquoted[] = $cid;
                $offset = max($offset, $attributeEnd);
            }
        }

        return array_values(array_unique([...$quoted, ...$unquoted]));
    }

    /**
     * The last of the attributes, which are in order, that starts from `$first` to `$last`
     *
     * @param  list<array{int, int, bool, string}>  $attributes  Each attribute's offset, end,
     *                                                           whether its ID is quoted, and ID
     * @return array{int, int, bool, string}|null
     */
    private static function lastAttribute(array $attributes, int $first, int $last): ?array
    {
        $low = 0;
        $high = count($attributes) - 1;
        $found = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if ($attributes[$middle][0] <= $last) {
                $found = $attributes[$middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found !== null && $found[0] >= $first ? $found : null;
    }

    /**
     * The reference that names the part, by its name or by its content ID, as Symfony matches them
     *
     * A reference the service would reject as a content ID is skipped, so its part is sent as a
     * regular attachment instead of failing the whole message.
     *
     * @param  list<string>  $references
     */
    private static function referenceTo(DataPart $part, array $references): ?string
    {
        // Angle brackets belong to the Content-ID header, not the ID the HTML references
        $contentId = $part->hasContentId() ? str_replace(['<', '>'], '', $part->getContentId()) : null;
        foreach ($references as $reference) {
            if (! self::isValidContentId($reference)) {
                continue;
            }
            if ($reference === $part->getName() || $reference === $contentId) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * Whether the mailing service accepts the content ID: 1 to 255 UTF-16 code units, none of them
     * whitespace or an angle bracket
     */
    private static function isValidContentId(string $cid): bool
    {
        if (! mb_check_encoding($cid, 'UTF-8') || preg_match(self::CONTENT_ID_FORBIDDEN_PATTERN, $cid) !== 0) {
            return false;
        }
        $length = strlen(mb_convert_encoding($cid, 'UTF-16LE', 'UTF-8')) / 2;

        return $length >= 1 && $length <= self::CONTENT_ID_MAX_LENGTH;
    }

    /**
     * Repeated headers, such as one X-Tag per tag, are joined into one comma-separated value, under
     * the first name's casing and in the order each name first appears. In-Reply-To and References
     * values are joined with a space instead, since each is a list of message IDs.
     *
     * @return array<string, string>|null
     */
    private static function headers(Email $email): ?array
    {
        $headers = [];
        $names = [];
        foreach ($email->getHeaders()->all() as $header) {
            $key = strtolower($header->getName());
            if (in_array($key, self::RESERVED_HEADERS, true)) {
                continue;
            }
            if (preg_match(self::HEADER_NAME_PATTERN, $header->getName()) !== 1) {
                throw new TransportException(sprintf('The header name "%s" is not valid.', $header->getName()));
            }
            $value = self::headerValue($header);
            if ($value === '') {
                continue;
            }
            $name = $names[$key] ??= $header->getName();
            $separator = in_array($key, self::SPACE_JOINED_HEADERS, true) ? ' ' : ', ';
            $headers[$name] = isset($headers[$name]) ? $headers[$name].$separator.$value : $value;
        }

        return $headers !== [] ? $headers : null;
    }

    /**
     * Text values are sent unencoded, so the service encodes them once when it builds the message.
     * Line breaks become spaces, so a value can never start a header of its own, and only spaces
     * and tabs are trimmed from the ends.
     */
    private static function headerValue(HeaderInterface $header): string
    {
        $value = $header instanceof UnstructuredHeader && ! $header instanceof ParameterizedHeader
            ? $header->getValue()
            : $header->getBodyAsString();

        return trim((string) preg_replace('/\r\n|\r|\n/', ' ', $value), " \t");
    }
}

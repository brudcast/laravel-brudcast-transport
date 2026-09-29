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
    private const HEADER_NAME_PATTERN = '/^[!-9;-~]+$/';

    /**
     * A content ID the mailing service accepts: up to 255 characters, no whitespace or angle brackets
     */
    private const CONTENT_ID_PATTERN = '/^[^\s<>]{1,255}$/';

    /**
     * Build the body of a send request to the Brudcast HTTP API
     *
     * With an envelope, its recipients decide who receives the message, as in Symfony's API
     * transports: `to` is every envelope recipient that is not a Cc or Bcc.
     *
     * @return array<string, mixed>
     *
     * @throws TransportException When a custom header has an invalid name
     */
    public static function fromEmail(Email $email, ?Envelope $envelope = null): array
    {
        $html = self::body($email->getHtmlBody());

        $payload = [
            'from' => self::addressOrList($email->getFrom()),
            'to' => self::addressList($envelope !== null ? self::envelopeTo($email, $envelope) : $email->getTo()),
            'cc' => self::addressList($email->getCc()),
            'bcc' => self::addressList($email->getBcc()),
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
            ? ['name' => $address->getName(), 'address' => $address->getAddress()]
            : ['address' => $address->getAddress()];
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
     * The envelope recipients that are not a Cc or Bcc, named as in the To header when they appear
     * there, since the envelope keeps only addresses
     *
     * @return list<Address>
     */
    private static function envelopeTo(Email $email, Envelope $envelope): array
    {
        $key = static fn (Address $address): string => strtolower($address->getAddress());
        $copied = array_map($key, [...$email->getCc(), ...$email->getBcc()]);
        $named = [];
        foreach ($email->getTo() as $address) {
            $named[$key($address)] ??= $address;
        }

        $to = [];
        foreach ($envelope->getRecipients() as $recipient) {
            if (! in_array($key($recipient), $copied, true)) {
                $to[] = $named[$key($recipient)] ?? $recipient;
            }
        }

        return $to;
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
     */
    private static function attachments(array $parts, array $references): ?array
    {
        $attachments = [];
        foreach (array_values($parts) as $index => $part) {
            $attachment = [
                'filename' => $part->getFilename()
                    ?: ($part->hasContentId() ? $part->getContentId() : 'attachment-'.($index + 1)),
                'content' => base64_encode($part->getBody()),
                'encoding' => 'base64',
                'contentType' => $part->getContentType(),
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
     * The `cid:` names in image sources and backgrounds, found the way Symfony finds them when it
     * builds the MIME message
     *
     * @return list<string>
     */
    private static function contentIdReferences(?string $html): array
    {
        if ($html === null) {
            return [];
        }
        preg_match_all(
            '/<(?:img\s+[^>]*src|\w+\s+[^>]*background)\s*=\s*(?:([\'"])cid:(.+?)\1|cid:([^>\s]+))/i',
            $html,
            $matches,
        );

        return array_values(array_unique(array_filter([...$matches[2], ...$matches[3]])));
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
        foreach ($references as $reference) {
            if (preg_match(self::CONTENT_ID_PATTERN, $reference) !== 1) {
                continue;
            }
            if ($reference === $part->getName() || ($part->hasContentId() && $reference === $part->getContentId())) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * Repeated headers, such as one X-Tag per tag, are joined into one comma-separated value
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
            $headers[$name] = isset($headers[$name]) ? $headers[$name].', '.$value : $value;
        }

        return $headers !== [] ? $headers : null;
    }

    /**
     * Text values are sent unencoded, so the service encodes them once when it builds the message.
     * Line breaks become spaces, so a value can never start a header of its own.
     */
    private static function headerValue(HeaderInterface $header): string
    {
        $value = $header instanceof UnstructuredHeader && ! $header instanceof ParameterizedHeader
            ? $header->getValue()
            : $header->getBodyAsString();

        return trim((string) preg_replace('/\r\n|\r|\n/', ' ', $value));
    }
}

<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Exceptions;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Mailer\Exception\TransportException;

final class BrudcastException extends TransportException
{
    /**
     * The HTTP-date formats RFC 9110 requires a recipient to accept: IMF-fixdate, then the obsolete
     * RFC 850 and asctime formats
     */
    private const HTTP_DATE_FORMATS = ['!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T', '!D M j H:i:s Y'];

    /**
     * The most seconds a `Retry-After` value is read as: the largest integer JavaScript represents
     * exactly, so the Nodemailer transport reads the same values
     */
    private const MAX_RETRY_AFTER = 9007199254740991;

    /**
     * @param  array<mixed>  $response
     */
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $response,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param  array<mixed>  $response
     * @param  int|null  $retryAfter  The seconds the response's `Retry-After` header asks to wait
     */
    public static function fromResponse(int $statusCode, array $response, ?int $retryAfter = null): self
    {
        $message = $response['message'] ?? null;

        return new self(
            is_string($message) && $message !== '' ? $message : "Brudcast responded with HTTP {$statusCode}",
            $statusCode,
            $response,
            $retryAfter,
        );
    }

    /**
     * The seconds a `Retry-After` header value asks to wait, given as a number of seconds or as an
     * HTTP-date, or null when it is missing, neither, or more seconds than 2^53 - 1
     *
     * @internal
     */
    public static function parseRetryAfter(string $value, ?int $now = null): ?int
    {
        $value = trim($value, " \t");
        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            // A cast saturates at PHP_INT_MAX, which is past the limit too
            $seconds = (int) $value;

            return $seconds <= self::MAX_RETRY_AFTER ? $seconds : null;
        }

        // asctime pads a one-digit day with a space
        $value = (string) preg_replace('/ {2,}/', ' ', $value);
        $utc = new DateTimeZone('UTC');
        foreach (self::HTTP_DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $utc);
            // A date that overflows, such as 31 February, is refused rather than rolled over
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return max(0, $date->getTimestamp() - ($now ?? time()));
            }
        }

        return null;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The seconds to wait before retrying, from the response's `Retry-After` header, as a `429`
     * carries it; null when the response has none or it cannot be read
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * The validation issues the service returned with a 422
     *
     * @return list<array{path: list<string|int>, message: string}>
     */
    public function getErrors(): array
    {
        $errors = $this->response['errors'] ?? [];

        return is_array($errors) ? array_values($errors) : [];
    }

    /**
     * The full response body
     *
     * @return array<mixed>
     */
    public function getResponse(): array
    {
        return $this->response;
    }
}

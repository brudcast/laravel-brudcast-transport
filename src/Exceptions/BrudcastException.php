<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport\Exceptions;

use Symfony\Component\Mailer\Exception\TransportException;

final class BrudcastException extends TransportException
{
    /**
     * @param  array<mixed>  $response
     */
    public function __construct(string $message, private readonly int $statusCode, private readonly array $response)
    {
        parent::__construct($message, $statusCode);
    }

    /**
     * @param  array<mixed>  $response
     */
    public static function fromResponse(int $statusCode, array $response): self
    {
        $message = $response['message'] ?? null;

        return new self(
            is_string($message) && $message !== '' ? $message : "Brudcast responded with HTTP {$statusCode}",
            $statusCode,
            $response,
        );
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
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

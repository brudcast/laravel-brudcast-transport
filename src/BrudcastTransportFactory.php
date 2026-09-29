<?php

declare(strict_types=1);

namespace Brudcast\LaravelTransport;

use Brudcast\LaravelTransport\Transport\BrudcastApiTransport;
use GuzzleHttp\Client;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use Symfony\Component\Mailer\Transport\TransportInterface;

final class BrudcastTransportFactory
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Create the transport for a mailer that uses the `brudcast` transport
     *
     * @param  array<string, mixed>  $config  The mailer's configuration from `config/mail.php`
     *
     * @throws InvalidArgumentException When no sending key is configured, or the endpoint is not
     *                                  HTTPS and the mailer does not allow `insecure` HTTP
     */
    public function make(array $config): TransportInterface
    {
        $key = filled($config['key'] ?? null) ? $config['key'] : $this->config->get('services.brudcast.key');
        // The service matches the key exactly, and a secret store can leave a trailing newline
        $key = is_string($key) ? trim($key) : '';
        if ($key === '') {
            throw new InvalidArgumentException(
                'laravel-brudcast-transport requires a key (a Brudcast sending key)'
            );
        }

        // Not `url`: Laravel parses a mailer's `url` as a DSN and replaces its transport with the scheme
        $endpoint = $config['endpoint'] ?? $this->config->get('services.brudcast.endpoint');
        $guzzle = $config['guzzle'] ?? [];

        return new BrudcastApiTransport(
            new Client([
                'timeout' => $config['timeout'] ?? BrudcastApiTransport::DEFAULT_TIMEOUT,
                'connect_timeout' => BrudcastApiTransport::DEFAULT_CONNECT_TIMEOUT,
                ...(is_array($guzzle) ? $guzzle : []),
            ]),
            $key,
            is_string($endpoint) && $endpoint !== '' ? $endpoint : BrudcastApiTransport::DEFAULT_URL,
            insecure: filter_var($config['insecure'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }
}

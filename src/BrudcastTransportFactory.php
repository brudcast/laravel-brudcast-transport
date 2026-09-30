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
    /**
     * The ASCII whitespace trimmed from a key: space, tab, line feed, carriage return, vertical tab
     * and form feed
     */
    private const KEY_WHITESPACE = " \t\n\r\x0B\x0C";

    /**
     * The string values that enable `insecure`, as FILTER_VALIDATE_BOOL reads them
     */
    private const TRUE_STRINGS = ['1', 'true', 'on', 'yes'];

    /**
     * A timeout given as a string: digits with an optional fraction, between optional ASCII
     * whitespace, so forms such as `1e3`, `.5` and `+5` are refused
     */
    private const TIMEOUT_PATTERN = '/^[ \t\n\r\x0B\x0C]*[0-9]+(\.[0-9]+)?[ \t\n\r\x0B\x0C]*$/D';

    /**
     * The shortest timeout, in seconds: Guzzle's cURL handler counts in whole milliseconds
     */
    private const MIN_TIMEOUT = 0.001;

    /**
     * The longest timeout, in seconds: the most milliseconds a signed 32-bit integer holds
     */
    private const MAX_TIMEOUT = 2147483.647;

    public function __construct(private readonly Repository $config) {}

    /**
     * Create the transport for a mailer that uses the `brudcast` transport
     *
     * @param  array<string, mixed>  $config  The mailer's configuration from `config/mail.php`
     *
     * @throws InvalidArgumentException When no sending key is configured, the endpoint is not a
     *                                  valid URL, is not HTTPS and the mailer does not allow
     *                                  `insecure` HTTP, or the timeout is not a positive number
     *                                  of seconds within range, or the key contains a character
     *                                  other than visible ASCII
     */
    public function make(array $config): TransportInterface
    {
        $key = $this->key($config);
        if ($key === '') {
            throw new InvalidArgumentException(
                'laravel-brudcast-transport requires a key (a Brudcast sending key)'
            );
        }

        $guzzle = $config['guzzle'] ?? [];

        return new BrudcastApiTransport(
            new Client([
                'timeout' => self::timeout($config['timeout'] ?? null),
                'connect_timeout' => BrudcastApiTransport::DEFAULT_CONNECT_TIMEOUT,
                ...(is_array($guzzle) ? $guzzle : []),
            ]),
            $key,
            $this->endpoint($config),
            insecure: self::insecure($config['insecure'] ?? false),
        );
    }

    /**
     * The first key that is a string and not blank, from the mailer and then the services config
     *
     * @param  array<string, mixed>  $config
     */
    private function key(array $config): string
    {
        foreach ([$config['key'] ?? null, $this->config->get('services.brudcast.key')] as $candidate) {
            // The service matches the key exactly, and a secret store can leave a trailing newline
            $key = is_string($candidate) ? trim($candidate, self::KEY_WHITESPACE) : '';
            if ($key !== '') {
                return $key;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException When the endpoint is set to something other than a string
     */
    private function endpoint(array $config): string
    {
        // Not `url`: Laravel parses a mailer's `url` as a DSN and replaces its transport with the scheme
        $endpoint = $config['endpoint'] ?? $this->config->get('services.brudcast.endpoint');
        if ($endpoint === null || $endpoint === '') {
            return BrudcastApiTransport::DEFAULT_URL;
        }
        if (! is_string($endpoint)) {
            throw new InvalidArgumentException(sprintf(
                'The Brudcast endpoint "%s" is not a valid URL.',
                is_scalar($endpoint) ? var_export($endpoint, true) : get_debug_type($endpoint),
            ));
        }

        return $endpoint;
    }

    /**
     * An environment variable arrives as a string, so "true", "on" and "yes" count as well
     */
    private static function insecure(mixed $insecure): bool
    {
        return $insecure === true
            || $insecure === 1
            || (is_string($insecure) && in_array(strtolower(trim($insecure)), self::TRUE_STRINGS, true));
    }

    /**
     * @throws InvalidArgumentException When the timeout is not a number of seconds from 0.001 to
     *                                  2147483.647, which Guzzle would only refuse when sending
     */
    private static function timeout(mixed $timeout): float
    {
        if ($timeout === null) {
            return BrudcastApiTransport::DEFAULT_TIMEOUT;
        }
        $seconds = is_int($timeout) || is_float($timeout) || (is_string($timeout) && preg_match(self::TIMEOUT_PATTERN, $timeout) === 1)
            ? (float) $timeout
            : null;
        // NAN fails both comparisons, and INF the second
        if ($seconds === null || ! ($seconds >= self::MIN_TIMEOUT && $seconds <= self::MAX_TIMEOUT)) {
            throw new InvalidArgumentException(sprintf(
                'The Brudcast timeout must be a positive number of seconds no greater than %s.',
                self::MAX_TIMEOUT,
            ));
        }

        return $seconds;
    }
}

# Laravel Brudcast Transport

![Brudcast: Reach Everyone, Every time][banner]

[![Latest Version on Packagist][packagist-badge]][packagist]
[![CI][ci-badge]][ci]
[![Coverage][coverage-badge]][coverage]
[![License][license-badge]](LICENSE)

A [Laravel][laravel] mail transport for the Brudcast mailing service. Mail is
sent through the Brudcast HTTP API using a sending key, so it works in
environments where outbound SMTP ports are blocked.

The transport behaves like any other Laravel mailer. Mailables, notifications,
queued mail and `Mail::fake()` work without changes, so switching an existing
application to Brudcast only requires a configuration change.

## Features

- Drop-in replacement: set `MAIL_MAILER=brudcast` and keep your existing code
- Delivers over HTTPS, with no SMTP connection required
- Includes `php artisan brudcast:verify` to check credentials without sending
  an email
- Supports PHP 8.2+ and Laravel 12+

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Options](#options)
  - [Endpoint](#endpoint)
  - [Migrating from another provider](#migrating-from-another-provider)
- [Usage](#usage)
  - [Sending mail](#sending-mail)
  - [Message fields](#message-fields)
  - [Response](#response)
  - [Error handling](#error-handling)
  - [Verifying credentials](#verifying-credentials)
- [Sending over SMTP](#sending-over-smtp)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Support](#support)
- [Credits](#credits)
- [License](#license)

## Requirements

- PHP 8.2 or later
- Laravel 12 or later

Laravel 13 requires PHP 8.3 or later.

You also need a Brudcast sending key for a verified domain.

## Installation

Install the package via Composer:

```bash
composer require brudcast/laravel-brudcast-transport
```

The service provider is registered automatically through Laravel's package
discovery.

## Configuration

Add a `brudcast` mailer to the `mailers` array in `config/mail.php`:

```php
'mailers' => [
    'brudcast' => [
        'transport' => 'brudcast',
        'key' => env('BRUDCAST_SENDING_KEY'),
    ],

    // ...
],
```

Then set it as the default mailer in your `.env` file:

```dotenv
MAIL_MAILER=brudcast
BRUDCAST_SENDING_KEY=your-sending-key
```

Do not commit sending keys to source control. Load them from the environment or
a secrets manager.

### Options

| Option     | Type     | Default   | Description                            |
| ---------- | -------- | --------- | -------------------------------------- |
| `key`      | `string` | None      | Brudcast sending key (required)        |
| `endpoint` | `string` | See below | Base URL of the mailing service        |
| `insecure` | `bool`   | `false`   | Allow `http://` endpoints              |
| `timeout`  | `int`    | `30`      | Request timeout in seconds             |
| `guzzle`   | `array`  | `[]`      | Extra [Guzzle request options][guzzle] |

If the mailer does not define `key` or `endpoint`, the transport falls back to
`services.brudcast.key` and `services.brudcast.endpoint` respectively.

Connecting to the service times out after 10 seconds. Set `connect_timeout`
under `guzzle` to change it.

### Endpoint

The `endpoint` option defaults to `https://mailing-service.brudcast.com`, and
most applications never need to change it. The send path is appended to any
path the endpoint has, so a prefix such as `https://proxy.example.com/brudcast`
is kept. A query string or fragment is dropped.

> [!NOTE]
> The option is named `endpoint` rather than `url` because Laravel treats a
> mailer's `url` value as a connection string and replaces its transport.

Because the sending key is sent in the `Authorization` header, the endpoint
must use HTTPS. An `http://` endpoint throws an `InvalidArgumentException` when
the mailer is created, unless the mailer sets `insecure` to `true`.

> [!WARNING]
> `insecure` sends the sending key unencrypted. Never enable it in production.

Redirects are not followed. If the endpoint responds with a redirect, the
request fails and the error message includes the target URL; update `endpoint`
to that URL.

### Migrating from another provider

Only the mailer configuration needs to change. Existing Mailables,
notifications and `Mail` facade calls continue to work as before.

If you are migrating from an API-based mailer such as Postmark, Resend or
Mailgun, replace that provider's entry in `config/services.php` with:

```php
'brudcast' => [
    'key' => env('BRUDCAST_SENDING_KEY'),
],
```

Next, replace the provider's mailer in `config/mail.php` with:

```php
'brudcast' => [
    'transport' => 'brudcast',
],
```

Finally, set `MAIL_MAILER=brudcast` in your `.env` file.

## Usage

### Sending mail

When Brudcast is the default mailer, all mail is sent through it:

```php
use App\Mail\Welcome;
use Illuminate\Support\Facades\Mail;

Mail::to('customer@example.com')->send(new Welcome($user));
```

If another mailer is the default, select Brudcast explicitly:

```php
Mail::mailer('brudcast')->to('customer@example.com')->send(new Welcome($user));
```

The `from` address must belong to a domain the sending key is authorized to
use. A domain key can only send from its own domain, while an organization key
can send from any of the organization's verified domains.

### Message fields

| Field                          | Supported | Notes                        |
| ------------------------------ | --------- | ---------------------------- |
| `from`                         | Yes       | Every address, see below     |
| `to`                           | Yes       | See below                    |
| `cc`, `bcc`                    | Yes       |                              |
| `replyTo`                      | Yes       | Every address                |
| `subject`                      | Yes       |                              |
| Text and HTML views            | Yes       | At least one is required     |
| Attachments                    | Yes       | Base64 encoded               |
| Custom headers                 | Yes       | Sent as string headers       |
| `tag()`, `metadata()`          | Yes       | Sent as string headers       |
| `priority()`                   | Yes       | Sent as `X-Priority`         |
| `Message-ID`, `Date`           | No        | Set by Brudcast              |
| `Sender`                       | No        | Set for several `from`       |
| `Return-Path`                  | No        | Set by Brudcast for bounces  |

Every `from` address is sent. When there is more than one, Brudcast lists them
all in the `From` header and sets the `Sender` header to the first, as RFC 5322
requires. All of them must be on the same domain, since that is the domain the
sending key is checked against and the message is DKIM signed for; otherwise
the request fails with a `400`.

The `to` recipients are taken from the Symfony envelope, consistent with
Symfony's own API transports: every envelope recipient that is not a Cc or Bcc
address. For standard Laravel mail, this is the `To` header. A custom envelope,
such as one set by Symfony's `EnvelopeListener`, only affects the `to`
recipients; Cc and Bcc addresses are always taken from the message itself.

The envelope sender is not sent, since Brudcast sets the Return-Path for bounce
handling. Every message requires at least one `to` recipient, so messages with
only Bcc recipients fail validation.

Blade and Markdown mail are rendered by Laravel before reaching the transport.
Images added with `$message->embed()` are sent with the content ID referenced
by the HTML, so they display inline. A reference Brudcast cannot use as a
content ID, one containing whitespace or angle brackets or longer than 255
characters, is sent as a regular attachment instead.

Repeated headers, such as the `X-Tag` header added for each `tag()`, are
combined into a single comma-separated value. Line breaks in header values are
replaced with spaces. A header name that is not valid under RFC 5322 (printable
ASCII without a colon) throws a `TransportException` before the request is
sent. Brudcast accepts header names of up to 78 characters and values of up to
998, so a very long combined value, such as many tags or large metadata, fails
validation with a `422`.

Brudcast builds the SMTP envelope, signs the message with the domain's DKIM key
and assigns the Message-ID.

### Response

`Mail::send()` returns once Brudcast has accepted the message for delivery (HTTP
`202`). The returned `SentMessage` uses the Brudcast job ID as its message ID,
and an `X-Brudcast-Job-Id` header is added to the message so that a
`MessageSent` listener can record it:

```php
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;

Event::listen(function (MessageSent $event) {
    $jobId = $event->message->getHeaders()->getHeaderBody('X-Brudcast-Job-Id');
});

$sent = Mail::to($user)->send(new Welcome($user));
$sent->getMessageId(); // "5f0c...@brudcast-mailing-service"
```

Since delivery is queued, a successful response means Brudcast has accepted the
message, not that the recipient's mail server has received it. Bounces and
suppressions are reported through Brudcast delivery events.

### Error handling

Failed requests throw a `BrudcastException`, which extends Symfony's
`TransportException` in the same way as other mailer failures:

```php
use Brudcast\LaravelTransport\Exceptions\BrudcastException;

try {
    Mail::to($user)->send(new Welcome($user));
} catch (BrudcastException $exception) {
    $exception->getStatusCode(); // HTTP status code, e.g. 400, 401, 422, 429
    $exception->getMessage();    // Error message returned by the service
    $exception->getErrors();     // Validation errors for a 422 response
    $exception->getResponse();   // Full response body
}
```

| Status | Description                                            |
| ------ | ------------------------------------------------------ |
| `400`  | The `from` domain is not registered or not permitted   |
| `401`  | The sending key is missing, invalid or revoked         |
| `403`  | Sending is blocked for the domain or organization      |
| `422`  | The message failed validation; see `getErrors()`       |
| `429`  | Too many requests; retry with backoff                  |
| `5xx`  | The service is unavailable; usually safe to retry      |

Network errors and timeouts throw a `TransportException` that wraps the
underlying Guzzle exception. Queued mail is retried according to the queue's
`tries` and `backoff` settings.

A `5xx` or a timeout does not always mean the message was not queued: a proxy
in front of Brudcast can time out after the service has accepted it. Retrying
in that case sends the message a second time.

### Verifying credentials

```bash
# Verify the default mailer
php artisan brudcast:verify

# Verify a specific mailer
php artisan brudcast:verify brudcast
```

The command confirms that the service is reachable and that the sending key is
accepted, without sending an email. It exits with a non-zero status code on
failure, so it can be used as part of a deployment check.

## Sending over SMTP

This package only supports the HTTP API. To send using Brudcast SMTP
credentials instead, use Laravel's built-in `smtp` mailer:

```php
'smtp' => [
    'transport' => 'smtp',
    'scheme' => env('MAIL_SCHEME', 'smtp'),
    'host' => env('MAIL_HOST', 'out-smtp.brudcast.com'),
    'port' => env('MAIL_PORT', 587),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
    'require_tls' => true,
],
```

`require_tls` makes the connection fail if the server does not offer STARTTLS,
instead of sending the password in plain text. Without it, Symfony upgrades to
TLS only when the server offers it. The option needs `symfony/mailer` 7.3 or
later; older versions ignore it.

Brudcast supports STARTTLS on ports `587`, `2525` and `25`, and implicit TLS on
port `465` (set `MAIL_SCHEME=smtps`). SMTP credentials are scoped to a single
sending domain, so the `from` address must belong to that domain.

## Testing

Clone the repository, install the dependencies and run the test suite:

```bash
composer install
composer test
```

To run static analysis and check code style:

```bash
composer analyse
composer lint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed in each release. The
changelog is generated from commit messages, and each version is also published
on the [releases][releases] page.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details on the development
setup, available scripts and commit conventions.

## Security Vulnerabilities

Please do not report security vulnerabilities through public issues. See
[SECURITY](SECURITY.md) for how to report them privately.

## Support

Found a bug or have a feature request? [Open an issue][issues]. Please include
the package, PHP and Laravel versions, and never paste a live sending key.

## Credits

- [Brudcast Limited][brudcast]
- Based on [laravel-sendgrid-driver][upstream] by Shingo Ichikawa

## License

The MIT License (MIT). Please see the [License File](LICENSE) for more
information.

[banner]: .github/assets/banner.svg
[packagist]: https://packagist.org/packages/brudcast/laravel-brudcast-transport
[packagist-badge]: https://img.shields.io/packagist/v/brudcast/laravel-brudcast-transport?color=365AED&label=packagist
[ci]: https://github.com/brudcast/laravel-brudcast-transport/actions/workflows/ci.yml
[ci-badge]: https://img.shields.io/github/actions/workflow/status/brudcast/laravel-brudcast-transport/ci.yml?branch=main&label=CI
[coverage]: https://codecov.io/gh/brudcast/laravel-brudcast-transport
[coverage-badge]: https://img.shields.io/codecov/c/github/brudcast/laravel-brudcast-transport?color=16A34A
[license-badge]: https://img.shields.io/packagist/l/brudcast/laravel-brudcast-transport?color=131313
[laravel]: https://laravel.com/docs/mail
[releases]: https://github.com/brudcast/laravel-brudcast-transport/releases
[issues]: https://github.com/brudcast/laravel-brudcast-transport/issues
[guzzle]: https://docs.guzzlephp.org/en/stable/request-options.html
[brudcast]: https://github.com/brudcast
[upstream]: https://github.com/s-ichikawa/laravel-sendgrid-driver

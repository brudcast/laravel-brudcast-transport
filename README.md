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
- Supports PHP 8.2+ with Laravel 12 or 13

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
- [Compatibility](#compatibility)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Support](#support)
- [Credits](#credits)
- [License](#license)

## Requirements

- PHP 8.2 or later
- Laravel 12 or 13

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
| `timeout`  | `float`  | `30`      | Request timeout in seconds             |
| `guzzle`   | `array`  | `[]`      | Extra [Guzzle request options][guzzle] |

If the mailer's `key` is missing, blank or not a string, the transport falls
back to `services.brudcast.key`. Leading and trailing spaces, tabs and line
breaks are removed from the key. The key must then contain only visible ASCII
characters (`!` to `~`); a key with any other character, such as an inner space,
line break or non-ASCII character, throws an `InvalidArgumentException` when the
mailer is created. The key itself never appears in an error message. If the
mailer does not define `endpoint`, the transport falls back to
`services.brudcast.endpoint`.

`insecure` is enabled by `true`, `1`, or one of the strings `"1"`, `"true"`,
`"on"` or `"yes"` in any case, so it can be read from an environment variable.

`timeout` accepts a number, or a string of digits with an optional decimal
part, such as `"5"` or `"2.5"`, optionally surrounded by ASCII whitespace. It
must be from `0.001` (one millisecond) to `2147483.647` seconds. Any other
value, including `0`, a value out of that range, or a string such as `"1e3"`,
`".5"` or `"+5"`, throws an `InvalidArgumentException` when the mailer is
created, rather than failing when mail is sent.

Connecting to the service times out after 10 seconds. Set `connect_timeout`
under `guzzle` to change it. Options under `guzzle` take precedence over
`timeout`.

### Endpoint

The `endpoint` option defaults to `https://mailing-service.brudcast.com`, and
most applications never need to change it. The send path is appended to any
path the endpoint has, so a prefix such as `https://proxy.example.com/brudcast`
is kept. A query string or fragment is dropped.

> [!NOTE]
> The option is named `endpoint` rather than `url` because Laravel treats a
> mailer's `url` value as a connection string and replaces its transport.

The endpoint is checked when the mailer is created, and an
`InvalidArgumentException` is thrown if it:

- is not an absolute URL with a host that starts with a scheme and `://`, or
  contains whitespace, a control character or a backslash
- includes a username or password; the sending key is the only credential
- does not use HTTPS

An error message that shows the endpoint replaces any username and password
with `***` and leaves out the query string and fragment, so it does not reveal
a secret.

Because the sending key is sent in the `Authorization` header, an `http://`
endpoint is only allowed when the mailer sets `insecure` to `true`. An empty or
`null` endpoint uses the default.

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
| `In-Reply-To`, `References`    | Yes       | Sent as string headers       |
| `tag()`, `metadata()`          | Yes       | Sent as string headers       |
| `priority()`                   | Yes       | Sent as `X-Priority` only    |
| `Message-ID`, `Date`           | No        | Set by Brudcast              |
| `Sender`                       | No        | Set for several `from`       |
| `Return-Path`                  | No        | Set by Brudcast for bounces  |

Every `from` address is sent. When there is more than one, Brudcast lists them
all in the `From` header and sets the `Sender` header to the first, as RFC 5322
requires. All of them must be on the same domain, since that is the domain the
sending key is checked against and the message is DKIM signed for; otherwise
the request fails with a `400`.

The recipients are taken from the Symfony envelope, consistent with Symfony's
own API transports. `to` lists, in envelope order, every envelope recipient in
the `To` header (named as it is there) and every envelope recipient in none of
the `To`, `Cc` or `Bcc` headers. A recipient in `To` and also in `Cc` or `Bcc`
stays in `to`. `cc` and `bcc` are the `Cc` and `Bcc` addresses that are envelope
recipients. For standard Laravel mail, the envelope holds every address in the
headers, so they are sent as they are. A custom envelope, such as one set by
Symfony's `EnvelopeListener`, can add or remove recipients.

Each of `to`, `cc` and `bcc` lists an address once, compared without regard to
case (including non-ASCII characters); the first occurrence is kept. Addresses
on an international domain are sent with the domain in its ASCII (punycode)
form, as Symfony's SMTP transport sends them. An address whose domain cannot be
converted is sent as it is, and Brudcast decides whether it is valid.

The envelope sender is not sent, since Brudcast sets the Return-Path for bounce
handling. Every message requires at least one `to` recipient, so messages with
only Bcc recipients fail validation.

Blade and Markdown mail are rendered by Laravel before reaching the transport.
Images added with `$message->embed()` are sent with the content ID referenced
by the HTML, in an `<img src>` or a `background` attribute, so they display
inline. A reference Brudcast cannot use as a content ID, one containing
whitespace or angle brackets or longer than 255 characters (counted in UTF-16
code units), is sent as a regular attachment instead. A reference is only found
when at most 4000 characters of other attributes come before it in its tag.

An attachment without a filename is named after its position and content type,
such as `attachment-1.png` (`.bin` when the type is unknown). The extension
comes from Symfony's MIME types, so it can differ from the one Nodemailer picks,
such as `.jpg` rather than `.jpeg` for `image/jpeg`. One added without
a content type is sent with the type its filename's extension suggests, such as
`application/pdf` for `report.pdf`. An empty attachment throws a
`TransportException` before the request is sent.

Custom headers, including `In-Reply-To` and `References`, are forwarded as
string headers. These headers are reserved and never forwarded, since Brudcast
sets them itself or reads them from the message fields: `Bcc`, `Cc`,
`Content-Transfer-Encoding`, `Content-Type`, `Date`, `From`, `Message-ID`,
`MIME-Version`, `Reply-To`, `Return-Path`, `Sender`, `Subject`, `To` and
`X-Brudcast-Job-Id`. `priority()` sets only the `X-Priority` header, which is
forwarded as it is.

Repeated headers, such as the `X-Tag` header added for each `tag()`, are
combined into a single comma-separated value, under the first one's name; a
repeated `In-Reply-To` or `References` is joined with a space instead. Line
breaks in header values are replaced with spaces, and leading and trailing
spaces and tabs are removed; a header left empty is not sent. Brudcast accepts
header names of up to 78 characters and values of up to 998, so a very long
combined value, such as many tags or large metadata, fails validation with a
`422`.

Symfony rejects most invalid header names with an `RfcComplianceException` when
the header is added. A name Symfony accepts but Brudcast does not, one that is
not printable ASCII without a colon as RFC 5322 requires, throws a
`TransportException` before the request is sent.

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
    $exception->getRetryAfter(); // Seconds from the Retry-After header, or null
    $exception->getResponse();   // Full response body
}
```

| Status | Description                                                 |
| ------ | ----------------------------------------------------------- |
| `400`  | The `from` domain is not registered or not permitted        |
| `401`  | The sending key is missing, invalid or revoked              |
| `403`  | Sending is blocked for the domain or organization           |
| `422`  | The message failed validation; see `getErrors()`            |
| `429`  | Too many requests; back off, see `getRetryAfter()`          |
| `5xx`  | The service failed; not always safe to retry, see below     |

The message is the service's own when it returns one, and otherwise
`Brudcast responded with HTTP <status>`. `getErrors()` always returns a list,
which is empty unless the service returned validation errors.

On a `429`, back off before retrying; a retry that comes too soon is rejected
again. When the response carries a `Retry-After` header, it is exposed as
`getRetryAfter()`, the number of seconds to wait, whether the header gives
seconds (up to 2^53 - 1, as the Nodemailer transport reads them) or an HTTP
date in any of the three forms HTTP allows; it is `null` when the header is
missing or cannot be read, including a date that does not exist or names the
wrong weekday. For queued mail, release the job for at least that long, or
give it a `backoff` that is.

A `5xx` is not always worth retrying: Brudcast also returns a `500` when the
request body is not valid JSON or is larger than 10 MB, such as a message with
large attachments, and retrying sends the same request again.

Network errors and timeouts throw a `TransportException` that wraps the
underlying Guzzle exception, with a message starting `Could not reach the
Brudcast mailing service:`. A message that cannot be encoded as JSON, such as
one with invalid UTF-8 text, throws a `TransportException` before the request
is sent. Queued mail is retried according to the queue's `tries` and `backoff`
settings.

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

It sends an empty message, which Brudcast only validates, and so rejects with a
`422`, once it has accepted the key. Only that validation response counts as
success; any other response, including a `422` from a proxy, fails. The sending
domain is not checked, so a domain that is not registered (`400`) or is blocked
(`403`) is only reported when mail is sent.

## Sending over SMTP

This package only supports the HTTP API. To send using Brudcast SMTP
credentials instead, use Laravel's built-in `smtp` mailer:

```php
'smtp' => [
    'transport' => 'smtp',
    'scheme' => env('MAIL_SCHEME', 'smtp'),
    'host' => env('MAIL_HOST', 'smtp.brudcast.com'),
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
port `465`, which Symfony uses automatically for that port. SMTP credentials are
scoped to a single sending domain, so the `from` address must belong to that
domain.

## Compatibility

| Dependency     | Supported                         |
| -------------- | --------------------------------- |
| PHP            | 8.2, 8.3, 8.4, 8.5                |
| Laravel        | 12, 13 (13 requires PHP 8.3+)     |
| Symfony Mailer | 7.2+ and 8                        |
| Guzzle         | 7.8+ and 8                        |

CI runs the suite on every supported PHP and Laravel combination, and on the
lowest supported dependency versions.

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

See the [releases][releases] for what has changed in each release. Release
notes are generated from commit messages.

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

# Contributing

## Setup

```bash
composer install
```

## Scripts

| Script                   | What it does                         |
| ------------------------ | ------------------------------------ |
| `composer test`          | Run the test suite                   |
| `composer test:coverage` | Run the tests with a coverage report |
| `composer lint`          | Check formatting with Pint           |
| `composer format`        | Format with Pint                     |
| `composer analyse`       | Run static analysis with Larastan    |

## Commits and releases

Commits and pull request titles follow [Conventional Commits][conventional].
Releases are cut by [release-please][release-please]: merging to `main` opens or
updates a release pull request, and merging that pull request tags the release
and updates `CHANGELOG.md`. Packagist picks up the tag automatically.

- `fix:` releases a patch
- `feat:` releases a minor
- `feat!:` or a `BREAKING CHANGE:` footer releases a major

## Compatibility

The transport must stay a drop-in Laravel mailer. CI runs the suite on every
supported PHP and Laravel version; keep `Mail::send`, queued mail, and the
`SentMessage` result consistent with Laravel's own mailers.

## Credits

The package started from [laravel-sendgrid-driver][upstream] by Shingo
Ichikawa, released under the MIT license.

[conventional]: https://www.conventionalcommits.org
[release-please]: https://github.com/googleapis/release-please
[upstream]: https://github.com/s-ichikawa/laravel-sendgrid-driver

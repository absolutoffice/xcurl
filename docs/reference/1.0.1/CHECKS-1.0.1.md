# xCurl 1.0.1 checks

This release adds packaging/documentation gates to the established xCurl QA suite:

- `composer phpdoc:check`: every runtime type and every source-declared runtime method under `src/` must have PHPDoc.
- `composer package:validate`: strict Composer metadata validation.
- `composer release:check`: also requires `license: LGPL-3.0-only` and a valid root `LICENSE` file.
- `composer brand:check`: legacy organization naming must not appear in package text sources.
- `composer test:package`: install a staged xCurl package into an isolated temporary Composer consumer and load it through that consumer's `vendor/autoload.php`.

The existing smoke, API, release, CS, PHPStan, unit, integration, fire, standalone-consumer, and phpDocumentor checks remain required.

On the reference Vulcano host, run:

```bash
composer update --lock
composer qa:report
```

The package-install gate must run under PHP 8.5+ with Composer available. A non-reference host lacking PHP 8.5 cannot validate the package requirement `php:^8.5`.

The `1.0.1-dev` candidate completed the full 14/14 QA report successfully on Vulcano/PHP 8.5.11 before promotion to `1.0.1`.

# Install xCurl with Composer

Use PHP **^8.5.12** (at least 8.5.12, below 9.0). Composer installation of the local checkout is verified; publishing, Packagist registration, tags and releases are separate maintainer actions, not performed by these checks.

## Local checkout: available now

In a separate consumer project, add this configuration to `composer.json`, replacing the absolute checkout path with yours:

```json
{
    "require": {"absolutoffice/xcurl": "dev-local"},
    "repositories": [
        {
            "type": "path",
            "url": "/absolute/path/to/xcurl",
            "options": {
                "symlink": false,
                "versions": {"absolutoffice/xcurl": "dev-local"}
            }
        },
        {"packagist.org": false}
    ],
    "config": {"allow-plugins": false}
}
```

Run in that consumer directory, not in the library checkout:

```bash
composer install --no-dev --no-plugins --no-scripts
composer check-platform-reqs --no-dev
```

This mirrors the current files instead of linking the source directory. The explicit version mapping is local test metadata, not a published version. Packagist is disabled here to avoid resolving another copy of xCurl. Merge repository settings thoughtfully if your application needs other dependencies.

Load only Composer's autoloader in application code:

```php
require_once __DIR__ . '/vendor/autoload.php';

$transport = \AbsolutOffice\XCurl\HttpTransportFactory::create();
```

The factory needs an available HTTPS backend: optional cURL, PHP HTTPS streams, or TLS/OpenSSL sockets. Composer installation alone does not prove network/TLS capability.

## Stable v1.1.0 through GitHub VCS: after tag publication

Once maintainers publish the `v1.1.0` Git tag, run these commands in a consumer project to select that stable version without Packagist registration:

```bash
composer config repositories.xcurl vcs https://github.com/absolutoffice/xcurl.git
composer require absolutoffice/xcurl:1.1.0 --no-plugins --no-scripts
composer check-platform-reqs --no-dev
```

Remove or replace a local path repository first so it cannot shadow the VCS repository. The release tag is not proven published by these local checks, and this VCS route has not been remotely installed. See [1.1.0 release notes](releases/1.1.0.md).

For ongoing **development** rather than a stable release, use the same VCS repository with:

```bash
composer require absolutoffice/xcurl:dev-main --no-plugins --no-scripts
```

`dev-main` tracks the remote `main` branch, which may differ from this checkout; it is not a substitute for the stable tag.

## Stable Packagist: future distribution

Only **after** maintainers publish `v1.1.0` and Packagist has registered the package and indexed that release, a consumer without the VCS repository override can use:

```bash
composer require absolutoffice/xcurl:^1.1 --no-plugins --no-scripts
composer check-platform-reqs --no-dev
```

The root `VERSION` file now records `1.1.0`, but neither it nor the changelog creates stable Composer metadata. Composer versions come from VCS branches/tags; the library deliberately has no root `version` field. Tag publication and Packagist indexing must be confirmed separately; these checks establish neither.

## Consumers versus contributors

- Consumers receive no xCurl `require-dev` tools. The only runtime Composer requirement is PHP `^8.5.12`.
- Contributors retain PHP-CS-Fixer, phpDocumentor, PHPStan and PHPUnit constraints. An isolated `composer update --dry-run --no-install --no-plugins --no-scripts --no-interaction --no-ansi` resolved all four tool constraints (70 packages); it did not install them or establish their execution coverage.
- The library's `config.platform.php = 8.5.12` sets its contributor solver baseline. Dependency packages' config does not configure a consumer's platform; `require.php` enforces the consumer minimum.
- A simulated platform does not upgrade PHP. Run `composer check-platform-reqs --no-dev` on the actual deployment runtime; do not bypass requirements with `--ignore-platform-reqs`.

## Reproduce local verification

From the checkout, with PHP 8.5.12 and Composer 2.10.3 (the tested versions):

```bash
composer validate --strict --no-check-lock
composer test
php tests/composer-consumer.php
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -l autoload.php
git diff --check
```

`tests/composer-consumer.php` uses `composer` on `PATH`. Set `XCURL_COMPOSER=/absolute/path/to/composer` for another executable, or `XCURL_COMPOSER=/absolute/path/to/composer.phar` to launch a PHAR using the running PHP binary. The value is one executable path, not a shell command or flags.

The dependency-free test creates a unique system-temporary tree, isolates Composer home/cache/config, disables Packagist/network access, and cleans only its owned tree in `finally`. It prints exact subprocess commands and output. It runs `composer install --no-dev --no-plugins --no-scripts --no-interaction --no-ansi` twice: simulated PHP 8.5.11 must fail specifically on xCurl's PHP requirement, while 8.5.12 must install a non-symlink path mirror. A fresh PHP process loads only installed `vendor/autoload.php` and exercises the public client/request/response API with a fake transport. No checkout `vendor/` or lock file is created.

Separately, it runs `composer archive --format=zip --dir=<owned-temp> --file=xcurl --no-plugins --no-scripts --no-interaction --no-ansi`, enumerates the actual ZIP, and checks runtime/license/test bytes against the checkout. Both `archive.exclude` and `.gitattributes` export rules exclude local indexes (`.atl`, `.codegraph`), `odd`, vendor/cache/output directories, lock/auth files, environment files, PDFs and key material. The runtime, `LICENSE`, `COPYING`, tests and Composer test script remain distributed. Export exclusions are not a replacement for keeping secrets out of Git.

Coverage is local mirrored-source installation plus real archive content validation, not installation from that ZIP, GitHub or Packagist. No real HTTPS integration or developer-tool suite is exercised by this smoke test. Historical PHP 8.5.11 QA statements remain historical.

## Standalone fallback

Keep `autoload.php` next to `src/` and load `require_once '/path/to/xcurl/autoload.php';` instead of Composer's autoloader. Deploy `LICENSE` and `COPYING` with the library. PHP `^8.5.12` remains the supported minimum, but the standalone autoloader does not perform Composer's platform checks.

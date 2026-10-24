# xCurl 1.0.1 Composer package contract

`1.0.1` converts the preferred distribution model from a local Composer-like tree to a normal Composer library package while preserving the frozen xCurl 1.0 runtime API and behavior.

## Consumer installation

```bash
composer require absolutoffice/xcurl
```

The consumer loads its own Composer autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

No consumer should hard-code `vendor/absolutoffice/xcurl` or otherwise depend on the physical package path.

## Package metadata

- package name: `absolutoffice/xcurl`
- type: `library`
- runtime requirement: exactly `php:^8.5`
- PSR-4: `AbsolutOffice\\XCurl\\` -> `src/`
- author/organization metadata: `Absolut Office`
- license: `LGPL-3.0-only`; the canonical license text is shipped as root `LICENSE`
- `composer.json` intentionally contains no `version` field; Composer derives released package versions from VCS tags
- `ext-curl` and `ext-openssl` remain suggestions/capability inputs rather than hard Composer requirements because xCurl has multiple runtime transports

## Compatibility

`autoload.php` and `VERSION` remain in the package for standalone compatibility. The existing `composer test:consumer` gate continues to prove that `autoload.php` + `VERSION` + `src/` can operate without a nested Composer vendor tree.

`composer test:package` adds the primary distribution test: it builds an isolated package staging tree, installs it into a temporary consumer through a Composer path repository with symlinking disabled, loads the consumer's `vendor/autoload.php`, and exercises public xCurl APIs.

## API checker semantics

The machine-readable 1.0 API snapshot moves from schema 1 to schema 2. Schema 2 removes `ReflectionParameter::isPromoted()` from parameter snapshots because private property promotion is an implementation detail, not a callable-signature compatibility promise. Public promoted properties are still represented independently by the public-property snapshot.

The schema migration changes no xCurl runtime signature. The expected snapshot transformation is limited to:

1. `schema: 1` -> `schema: 2`;
2. removal of every parameter-level `promoted` key.

## PHPDoc and branding gates

`composer phpdoc:check` requires a PHPDoc block on every runtime type and every source-declared runtime method under `src/`, including internal methods. This is a source-documentation contract and does not alter runtime behavior.

`composer brand:check` rejects legacy organization naming from package text sources. The reviewed `1.0.0` source tree contained no such reference; current package metadata identifies Absolut Office.

## License

The package is distributed under the GNU Lesser General Public License version 3 only (`LGPL-3.0-only`). The root `LICENSE` file is part of the Composer package and `composer release:check` verifies both the SPDX metadata and the expected license text.

## Release archive

Composer archive exclusions remove development-only material such as tests, tools, caches, generated API HTML, historical notes, the repository lock file, and the repository's own vendor tree. Runtime source, package metadata, README/changelog, standalone compatibility files, and active documentation remain distributable.

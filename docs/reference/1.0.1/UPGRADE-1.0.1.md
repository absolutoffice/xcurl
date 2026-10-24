# Upgrade to xCurl 1.0.1 packaging model

The preferred consumer installation changes from manually placing xCurl under `[BaseDir]/lib/xCurl` to requiring the package through Composer:

```bash
composer require absolutoffice/xcurl
```

Consumer bootstrap code should load the project's Composer autoloader rather than xCurl's physical path:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

No namespace, class, method, parameter, error enum, retry/redirect rule, transport-selection rule, or TLS/framing behavior changes in this revision.

The standalone `autoload.php` + `VERSION` + `src/` deployment remains supported for existing installations, but new integrations should use Composer.

The Composer package is distributed under `LGPL-3.0-only`; see the root `LICENSE` file.

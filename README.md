# xCurl

xCurl is an HTTPS-only transport library for PHP **^8.5.12** projects. It selects an available backend (cURL, PHP HTTPS streams, or a direct TLS socket) and offers an optional client for redirects, retries, and deadlines. TLS peer and hostname verification remain enabled.

## Install and try it

Prefer Composer and load its generated autoloader. The stable release target is **v1.1.0**; see the [release notes](docs/releases/1.1.0.md). Once maintainers publish that Git tag, install it directly through GitHub VCS in a consumer project:

```bash
composer config repositories.xcurl vcs https://github.com/absolutoffice/xcurl.git
composer require absolutoffice/xcurl:1.1.0 --no-plugins --no-scripts
composer check-platform-reqs --no-dev
```

The [installation guide](docs/INSTALLATION.md) also covers verified local path installation and the `dev-main` development option. Without a VCS repository override, `composer require absolutoffice/xcurl:^1.1` is valid **only after Packagist has indexed the release**. This checkout does not establish tag publication, indexing, or successful remote installation.

```php
require_once __DIR__ . '/vendor/autoload.php';

use AbsolutOffice\XCurl\HttpTransportFactory;

$transport = HttpTransportFactory::create();
$response = $transport->request('GET', 'https://example.com/', [], 30);
echo $response->statusCode, "\n";
```

Composer provides PSR-4 autoloading under `AbsolutOffice\XCurl\`. The runtime has no Composer dependency beyond PHP itself; contributor tools are not consumer dependencies. cURL is optional; HTTPS streams require the HTTPS wrapper, and the direct socket requires TLS/OpenSSL support.

For standalone deployment instead, keep `autoload.php` alongside `src/` and replace the require above with `require_once __DIR__ . '/xcurl/autoload.php';` (adjust the checkout path). The same PHP minimum applies.

## Redirects and downloads

A transport makes **one request** and does not follow redirects. Use `HttpClient` to opt into client-level policy:

```php
use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\RequestOptions;
use AbsolutOffice\XCurl\HttpRequest;
use AbsolutOffice\XCurl\HttpTransportFactory;

$client = new HttpClient(HttpTransportFactory::create());
$result = $client->send(
    new HttpRequest('GET', 'https://example.com/file', []),
    new RequestOptions(timeoutSeconds: 30, targetPath: __DIR__ . '/download.tmp'),
);
// $result->response, $result->finalUrl, and $result->history describe the outcome.
```

The target path must **not already exist** when using the client: it refuses an existing file or symlink before making a request. Built-in transports create download files exclusively and report which completed attempts actually created one. Only such intermediate files are removed before a redirect or response retry; no-body responses need no target. Use a new path for each operation and arrange final-file promotion in your application. A custom transport that creates targets must set `HttpResponse::targetCreated` on successful file creation, or continuation stops safely if an unowned file appears.

The factory selects cURL, then HTTPS streams, then the direct TLS socket based on runtime capability. `createStrict()` excludes streams for stricter response-framing checks. **Selection is not runtime failover**: a selected backend's failed request will not switch to another backend. Default client retries apply only to GET/HEAD transient failures and retryable statuses; other statuses can be configured with `RetryPolicy`.

## Check and reference

Run `composer test` (or `php tests/regression.php`) for dependency-free policy and socket-connector checks, `php tests/composer-consumer.php` for isolated Composer installation/archive checks, and `find src tests -name '*.php' -print0 | xargs -0 -n1 php -l` for PHP syntax. These checks do not establish complete TLS or cURL/streams integration coverage; no full QA suite is bundled in this checkout.

See [documentation index](docs/README.md) for historical reference material. For API details, see [1.0.0 reference](docs/reference/1.0.0/API-1.0.0.md); treat historical release and QA statements there as archival, not as verification of this snapshot.

Licensed under [GNU LGPL-3.0-only](LICENSE), which incorporates the [GNU GPLv3 terms](COPYING). Both license texts are included.

# xCurl

### English

`xCurl` is an Absolut Office HTTPS transport and policy package for generic PHP projects.

It was created for environments where hosting providers, ISPs, proxies, firewalls, or server configurations restrict or disable parts of the standard PHP networking stack.

Its purpose is to provide a resilient HTTPS transport layer that can progressively select among supported connection backends according to the capabilities available at runtime. Rather than depending on a single networking mechanism, `xCurl` can use cURL, PHP HTTPS streams, or a direct TLS socket when the preferred backend is not available.

The goal is simple: make outbound HTTPS communication more reliable across constrained or inconsistently configured PHP environments, while preserving explicit transport policies, TLS verification, and security controls.

More documentation: xCurl 1.1.0 Practical Manual
---

### Español

`xCurl` es un paquete de Absolut Office para transporte HTTPS y gestión de políticas, diseñado para proyectos PHP genéricos.

Nace para aquellos entornos en los que proveedores de hosting, ISP, proxies, firewalls o determinadas configuraciones del servidor limitan o deshabilitan partes de la conectividad estándar disponible en PHP.

Su propósito es proporcionar una capa de transporte HTTPS resiliente, capaz de seleccionar progresivamente distintos backends de conexión compatibles según las capacidades disponibles en tiempo de ejecución. En lugar de depender de un único mecanismo de red, `xCurl` puede utilizar cURL, streams HTTPS de PHP o un socket TLS directo cuando el backend preferido no está disponible.

El objetivo es sencillo: hacer más fiable la comunicación HTTPS saliente en entornos PHP restringidos o configurados de forma desigual, manteniendo políticas de transporte explícitas, verificación TLS y controles de seguridad.

Más documentación: Manual práctico de xCurl 1.1.0
---

### Português

`xCurl` é um pacote da Absolut Office para transporte HTTPS e gestão de políticas, destinado a projetos PHP genéricos.

Foi criado para ambientes em que provedores de alojamento, ISPs, proxies, firewalls ou determinadas configurações do servidor limitam ou desativam partes da conectividade padrão disponível no PHP.

O seu objetivo é fornecer uma camada de transporte HTTPS resiliente, capaz de selecionar progressivamente diferentes backends de ligação compatíveis de acordo com as capacidades disponíveis em tempo de execução. Em vez de depender de um único mecanismo de rede, o `xCurl` pode utilizar cURL, streams HTTPS do PHP ou um socket TLS direto quando o backend preferencial não está disponível.

O objetivo é simples: tornar a comunicação HTTPS de saída mais fiável em ambientes PHP restritos ou configurados de forma inconsistente, mantendo políticas de transporte explícitas, verificação TLS e controlos de segurança.

Mais documentação: Manual prático do xCurl 1.1.0
---

### Français

`xCurl` est un package Absolut Office de transport HTTPS et de gestion des politiques, destiné aux projets PHP génériques.

Il a été conçu pour les environnements dans lesquels les hébergeurs, FAI, proxies, pare-feu ou certaines configurations serveur limitent ou désactivent une partie des mécanismes réseau normalement disponibles en PHP.

Son objectif est de fournir une couche de transport HTTPS résiliente, capable de sélectionner progressivement différents backends de connexion compatibles selon les capacités disponibles à l'exécution. Au lieu de dépendre d'un seul mécanisme réseau, `xCurl` peut utiliser cURL, les streams HTTPS de PHP ou un socket TLS direct lorsque le backend privilégié n'est pas disponible.

L'objectif est simple : rendre les communications HTTPS sortantes plus fiables dans les environnements PHP contraints ou configurés de manière hétérogène, tout en conservant des politiques de transport explicites, la vérification TLS et les contrôles de sécurité.

Documentation complémentaire : Manuel pratique de xCurl 1.1.0
## Install

xCurl requires PHP **^8.5.12**.

The current stable release is **v1.1.0**.

### Via Composer

Install xCurl through Packagist:

```bash
composer require absolutoffice/xcurl:^1.1
```

### Directly from GitHub VCS

To install the stable release directly from GitHub:

```bash
composer config repositories.xcurl vcs https://github.com/absolutoffice/xcurl.git
composer require absolutoffice/xcurl:1.1.0 --no-plugins --no-scripts
```

### Load xCurl

Load Composer's generated autoloader from your application bootstrap:

```php
require_once __DIR__ . '/vendor/autoload.php';

use AbsolutOffice\XCurl\HttpTransportFactory;

$transport = HttpTransportFactory::create();

$response = $transport->request(
    'GET',
    'https://example.com/',
    [],
    30
);

echo $response->statusCode, "\n";
```

Composer provides PSR-4 autoloading under `AbsolutOffice\XCurl\`.

xCurl has no mandatory Composer runtime dependency other than PHP itself. cURL is optional; HTTPS streams require the PHP HTTPS wrapper, and the direct socket transport requires TLS/OpenSSL support.

## Verify the installation

To verify that the installed package and the real runtime environment satisfy the required PHP version and platform requirements, run:

```bash
composer check-platform-reqs --no-dev
```

You can also perform a runtime capability check:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use AbsolutOffice\XCurl\HttpTransportFactory;

$diagnostics = HttpTransportFactory::diagnostics();

print_r($diagnostics);
```

This reports the HTTPS transport capabilities detected by xCurl in the current PHP runtime.

For a basic HTTPS request test:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use AbsolutOffice\XCurl\HttpTransportFactory;

$transport = HttpTransportFactory::create();

$response = $transport->request(
    'GET',
    'https://example.com/',
    [],
    30
);

echo 'HTTP status: ', $response->statusCode, PHP_EOL;
```

A successful response confirms that xCurl can select an available HTTPS transport and complete a request in the current environment.

## Install and try it

To inspect, test, or contribute to xCurl, clone the repository:

```bash
git clone https://github.com/absolutoffice/xcurl.git
cd xcurl
composer install
```

Run the available project checks:

```bash
composer test
```

For an isolated Composer consumer/archive check:

```bash
php tests/composer-consumer.php
```

For a PHP syntax check:

```bash
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

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

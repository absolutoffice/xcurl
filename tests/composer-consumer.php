<?php

declare(strict_types=1);

// No dependencies or repository-local output; Composer must already be installed.
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeJson(string $path, array $data): void
{
    check(file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") !== false, 'Cannot write manifest.');
}

/** @return array{int, string} */
function run(array $command, string $cwd, array $environment, string $temporary): array
{
    echo '$ ', implode(' ', array_map(escapeshellarg(...), $command)), ' (cwd: ', $cwd, ")\n";
    $log = $temporary . '/command-' . bin2hex(random_bytes(8)) . '.log';
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    check(is_resource($process), 'Cannot start command.');
    fclose($pipes[0]);
    $status = proc_close($process);
    $output = file_get_contents($log);
    check($output !== false, 'Cannot read command output.');
    echo $output;
    return [$status, $output];
}

function assertDistributedPath(string $path): void
{
    check(!preg_match('~(^|/)(\.git|\.atl|\.codegraph|odd|vendor|var|\.phpunit\.cache)(/|$)|(^|/)(\.env[^/]*|auth\.json|composer\.lock|\.php-cs-fixer\.cache|id_(rsa|dsa|ecdsa|ed25519)[^/]*)(/|$)|\.(pdf|pem|key|p12|pfx)$~i', $path), 'Forbidden distribution entry: ' . $path);
}

// Only this freshly created tree is removed. Never follow a directory symlink.
function cleanOwnedTree(string $directory): void
{
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            check(rmdir($file->getPathname()), 'Cannot remove owned directory.');
        } else {
            check(unlink($file->getPathname()), 'Cannot remove owned file.');
        }
    }
    check(rmdir($directory), 'Cannot remove owned temporary root.');
}

check(PHP_VERSION_ID >= 80512, 'Run with actual PHP >=8.5.12; platform simulation does not replace the runtime.');
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
check($manifest['require'] === ['php' => '^8.5.12'], 'Unexpected runtime requirements.');
check($manifest['config']['platform']['php'] === '8.5.12', 'Unexpected contributor platform.');
check(!isset($manifest['version']), 'Package versions must come from VCS, not a root version field.');
// Fail before any mirror can copy local data if export rules drift from archive rules.
$attributes = file($root . '/.gitattributes', FILE_IGNORE_NEW_LINES);
foreach ($manifest['archive']['exclude'] as $pattern) {
    check(in_array($pattern . ' export-ignore', $attributes, true), 'Missing mirror exclusion: ' . $pattern);
}
$executable = getenv('XCURL_COMPOSER') ?: 'composer';
$composer = str_ends_with($executable, '.phar') ? [PHP_BINARY, $executable] : [$executable];
$temporary = sys_get_temp_dir() . '/xcurl-composer-' . bin2hex(random_bytes(12));
check(mkdir($temporary, 0700), 'Cannot create owned temporary root.');
$temporary = realpath($temporary); // macOS /var aliases /private/var in loaded file paths.

try {
    // Isolate global Composer configuration, authentication, cache and plugins.
    $environment = getenv();
    foreach (array_keys($environment) as $key) {
        if (str_starts_with($key, 'COMPOSER_') || $key === 'COMPOSER') {
            unset($environment[$key]);
        }
    }
    $environment = array_merge($environment, [
        'HOME' => $temporary . '/home',
        'XDG_CONFIG_HOME' => $temporary . '/config',
        'COMPOSER_HOME' => $temporary . '/composer-home',
        'COMPOSER_CACHE_DIR' => $temporary . '/cache',
        'COMPOSER_AUTH' => '{}',
        'COMPOSER_DISABLE_NETWORK' => '1',
        'COMPOSER_NO_INTERACTION' => '1',
    ]);
    $flags = ['--no-plugins', '--no-scripts', '--no-interaction', '--no-ansi'];
    $required = ['composer.json', '.gitattributes', 'autoload.php', 'VERSION', 'LICENSE', 'COPYING', 'tests/regression.php', 'tests/composer-consumer.php'];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        check(!$file->isLink() && $file->isFile(), 'Runtime must contain regular files.');
        $required[] = 'src/' . substr($file->getPathname(), strlen($root . '/src/'));
    }

    // Verify a real Composer archive independently, never a filtered candidate copy.
    [$status] = run([...$composer, 'archive', '--format=zip', '--dir=' . $temporary, '--file=xcurl', ...$flags], $root, $environment, $temporary);
    check($status === 0, 'Composer archive failed.');
    $zip = new PharData($temporary . '/xcurl.zip');
    $prefix = 'phar://' . $temporary . '/xcurl.zip/';
    foreach (new RecursiveIteratorIterator($zip) as $file) {
        $name = substr($file->getPathname(), strlen($prefix));
        assertDistributedPath($name);
        echo 'archive: ', $name, "\n";
    }
    foreach ($required as $name) {
        check(isset($zip[$name]), 'Archive missing ' . $name);
        check(hash('sha256', $zip[$name]->getContent()) === hash_file('sha256', $root . '/' . $name), 'Archive content differs: ' . $name);
    }
    unset($zip);
    echo "PASS: actual archive includes runtime, licenses and test scripts; local artifacts excluded.\n";

    $repository = [
        'type' => 'path',
        'url' => $root,
        'options' => ['symlink' => false, 'versions' => ['absolutoffice/xcurl' => 'dev-consumer-test']],
    ];
    foreach (['8.5.11', '8.5.12'] as $platform) {
        $consumer = $temporary . '/php-' . $platform;
        check(mkdir($consumer, 0700), 'Cannot create consumer directory.');
        writeJson($consumer . '/composer.json', [
            'name' => 'xcurl-test/consumer',
            'require' => ['absolutoffice/xcurl' => 'dev-consumer-test'],
            'repositories' => [$repository, ['packagist.org' => false]],
            'config' => ['platform' => ['php' => $platform], 'allow-plugins' => false],
        ]);
        [$status, $output] = run([...$composer, 'install', '--no-dev', ...$flags], $consumer, $environment, $temporary);
        if ($platform === '8.5.11') {
            check($status === 2, 'Expected dependency solver failure for PHP 8.5.11.');
            check((bool) preg_match('/absolutoffice\/xcurl[^\r\n]*requires php \^8\.5\.12[^\r\n]*8\.5\.11/', $output), 'Failure was not caused by xCurl PHP ^8.5.12 rejecting 8.5.11.');
            check(!file_exists($consumer . '/vendor/autoload.php'), 'Rejected consumer unexpectedly installed.');
            echo "PASS: simulated PHP 8.5.11 rejected specifically by xCurl PHP requirement.\n";
            continue;
        }
        check($status === 0, 'PHP 8.5.12 consumer install failed.');
        $installed = $consumer . '/vendor/absolutoffice/xcurl';
        check(is_dir($installed) && !is_link($installed), 'Consumer must use a mirror, not a symlink.');
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($installed, FilesystemIterator::SKIP_DOTS)) as $file) {
            check(!$file->isLink(), 'Installed package must not contain symlinks.');
            assertDistributedPath(substr($file->getPathname(), strlen($installed) + 1));
        }
        foreach ($required as $name) {
            check(is_file($installed . '/' . $name), 'Installed package missing ' . $name);
            check(hash_file('sha256', $installed . '/' . $name) === hash_file('sha256', $root . '/' . $name), 'Installed content differs: ' . $name);
        }
        $lock = json_decode(file_get_contents($consumer . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        check(count($lock['packages']) === 1 && $lock['packages-dev'] === [], 'Unexpected consumer dependencies.');
        [$status] = run([...$composer, 'check-platform-reqs', '--no-dev', ...$flags], $consumer, $environment, $temporary);
        check($status === 0, 'Actual PHP runtime platform check failed.');

        $smoke = <<<'PHP'
require $argv[1] . '/vendor/autoload.php';

use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\RequestOptions;
use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\HttpRequest;
use AbsolutOffice\XCurl\HttpResponse;

$transport = new class implements HttpTransportInterface {
    public int $calls = 0;
    public function request(string $method, string $url, array $headers, int $timeoutSeconds, ?string $targetPath = null, ?string $requestBody = null): HttpResponse
    {
        ++$this->calls;
        if ($method !== 'POST' || $url !== 'https://example.test/' || $requestBody !== 'payload' || $targetPath !== null) {
            throw new RuntimeException('Public request contract differs.');
        }
        return new HttpResponse(200, ['Content-Type: text/plain'], 'ok', 2);
    }
};
$result = (new HttpClient($transport))->send(
    new HttpRequest('POST', 'https://example.test/', [], 'payload'),
    new RequestOptions(timeoutSeconds: 5),
);
if ($result->response->statusCode !== 200 || $result->response->body !== 'ok' || $transport->calls !== 1) {
    throw new RuntimeException('Installed public API smoke failed.');
}
foreach (get_included_files() as $file) {
    if (!str_starts_with($file, $argv[1] . '/vendor/')) {
        throw new RuntimeException('Loaded code outside installed vendor tree.');
    }
}
echo "PASS: fresh PHP loads only installed vendor/autoload.php and exercises fake-transport public API.\n";
PHP;
        [$status] = run([PHP_BINARY, '-r', $smoke, $consumer], $temporary, $environment, $temporary);
        check($status === 0, 'Fresh installed-consumer process failed.');
    }
    echo "PASS: Composer consumer and archive checks complete; no registry publication tested.\n";
} finally {
    cleanOwnedTree($temporary);
}

<?php

declare(strict_types=1);

// Stub only the stream wrapper's connection/metadata boundary; exercise the real
// StreamHttpTransport no-body branch without contacting a network endpoint.
namespace AbsolutOffice\XCurl {
    function fopen(string $filename, string $mode, bool $useIncludePath = false, $context = null)
    {
        if (str_starts_with($filename, 'https://example.test/fixture')) {
            $stream = \fopen('php://temp', 'w+b');
            $GLOBALS['xcurlFixtureStream'] = $stream;
            return $stream;
        }
        return $context === null
            ? \fopen($filename, $mode, $useIncludePath)
            : \fopen($filename, $mode, $useIncludePath, $context);
    }

    function stream_get_meta_data($stream): array
    {
        if ($stream === ($GLOBALS['xcurlFixtureStream'] ?? null)) {
            return [
                'wrapper_data' => $GLOBALS['xcurlFixtureHeaders'],
                'timed_out' => false,
            ];
        }
        return \stream_get_meta_data($stream);
    }
}

namespace {
require_once dirname(__DIR__) . '/autoload.php';

use AbsolutOffice\XCurl\Client\ClientError;
use AbsolutOffice\XCurl\Client\HttpClient;
use AbsolutOffice\XCurl\Client\HttpClientException;
use AbsolutOffice\XCurl\Client\RequestOptions;
use AbsolutOffice\XCurl\Client\RetryPolicy;
use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\HttpRequest;
use AbsolutOffice\XCurl\HttpResponse;
use AbsolutOffice\XCurl\SocketHttpTransport;
use AbsolutOffice\XCurl\StreamHttpTransport;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ScriptedTransport implements HttpTransportInterface
{
    /** @param list<Closure(?string):HttpResponse> $steps */
    public function __construct(private array $steps) {}

    public int $calls = 0;

    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse {
        $step = $this->steps[$this->calls] ?? throw new RuntimeException('Unexpected transport request.');
        ++$this->calls;
        return $step($targetPath);
    }
}

function createTarget(string $path, string $body): void
{
    $file = fopen($path, 'xb');
    check($file !== false, 'Unable to create exclusive fixture target.');
    try {
        check(fwrite($file, $body) === strlen($body), 'Unable to write fixture target.');
    } finally {
        fclose($file);
    }
}

/** @return array{0: resource, 1: resource} */
function scriptedSocket(string $wire): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    check($pair !== false, 'Unable to create local socket fixture.');
    try {
        check(fwrite($pair[0], $wire) === strlen($wire), 'Unable to write local socket fixture.');
        return $pair;
    } catch (Throwable $exception) {
        fclose($pair[0]);
        fclose($pair[1]);
        throw $exception;
    }
}

/** @param array{0: resource, 1: resource}|null $pair */
function closeScriptedSocket(?array $pair): void
{
    if ($pair !== null) {
        foreach ($pair as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }
}

$directory = sys_get_temp_dir() . '/xcurl-regression-' . bin2hex(random_bytes(8));
check(mkdir($directory, 0700), 'Unable to create regression fixture directory.');
$target = $directory . '/download';

try {
    // Refusal must happen before any request: even HEAD and no-body responses can leave
    // an existing target untouched while the old client would later unlink it.
    foreach ([
        ['HEAD', 302, ['Location: /next'], new RetryPolicy(maxRetries: 0)],
        ['HEAD', 503, [], new RetryPolicy(maxRetries: 1, backoffMilliseconds: [0])],
        ...array_map(
            static fn(int $status): array => [
                'GET', $status, [], new RetryPolicy(
                    maxRetries: 1, backoffMilliseconds: [0], statusCodes: [$status], retryServerErrors: false,
                ),
            ],
            [204, 205, 304],
        ),
    ] as [$method, $status, $headers, $retry]) {
        file_put_contents($target, 'user data');
        $transport = new ScriptedTransport([
            static fn(?string $path): HttpResponse => new HttpResponse($status, $headers),
        ]);
        try {
            (new HttpClient($transport, $retry))->send(
                new HttpRequest($method, 'https://example.test/start', []),
                new RequestOptions(5, $target),
            );
            throw new RuntimeException('Existing target should be refused.');
        } catch (InvalidArgumentException $exception) {
            check(str_contains($exception->getMessage(), 'already exists'), 'Wrong preflight rejection.');
        }
        check($transport->calls === 0, 'Existing target reached transport.');
        check(file_get_contents($target) === 'user data', 'Existing target changed.');
        unlink($target);
    }

    foreach ([302, 503] as $status) {
        $first = static function (?string $path) use ($status): HttpResponse {
            check($path !== null, 'Missing target for attempt.');
            createTarget($path, '');
            return new HttpResponse($status, $status === 302 ? ['Location: /next'] : [], '', 0, true);
        };
        $second = static function (?string $path): HttpResponse {
            check($path !== null && !file_exists($path), 'Owned intermediate was not removed.');
            createTarget($path, 'download');
            return new HttpResponse(200, [], '', 8, true);
        };
        $transport = new ScriptedTransport([$first, $second]);
        $client = new HttpClient($transport, new RetryPolicy(maxRetries: 1, backoffMilliseconds: [0]));
        $result = $client->send(new HttpRequest('GET', 'https://example.test/start', []), new RequestOptions(5, $target));
        check($transport->calls === 2 && $result->response->targetCreated, 'Download continuation failed.');
        check(file_get_contents($target) === 'download', 'Final download differs.');
        unlink($target);
    }

    // No-body attempt has no owned file; redirect/retry still continues normally.
    foreach ([['HEAD', 302], ['HEAD', 503], ['GET', 302], ['GET', 204], ['GET', 205], ['GET', 304]] as [$method, $status]) {
        $transport = new ScriptedTransport([
            static fn(?string $path): HttpResponse => new HttpResponse(
                $status, $status === 302 ? ['Location: /next'] : [],
            ),
            static function (?string $path): HttpResponse {
                check($path !== null && !file_exists($path), 'No-body attempt left a target.');
                createTarget($path, 'ok');
                return new HttpResponse(200, [], '', 2, true);
            },
        ]);
        $retry = new RetryPolicy(
            maxRetries: 1, backoffMilliseconds: [0], statusCodes: [$status], retryServerErrors: false,
        );
        (new HttpClient($transport, $retry))->send(
            new HttpRequest($method, 'https://example.test/start', []), new RequestOptions(5, $target),
        );
        check($transport->calls === 2 && file_get_contents($target) === 'ok', 'No-body continuation failed.');
        unlink($target);
    }

    $transport = new ScriptedTransport([
        static function (?string $path): HttpResponse {
            check($path !== null, 'Missing target.');
            file_put_contents($path, 'external');
            return new HttpResponse(302, ['Location: /next']); // No ownership claim.
        },
    ]);
    try {
        (new HttpClient($transport))->send(
            new HttpRequest('GET', 'https://example.test/start', []), new RequestOptions(5, $target),
        );
        throw new RuntimeException('Unowned target should stop continuation.');
    } catch (HttpClientException $exception) {
        check($exception->error === ClientError::TARGET_CLEANUP, 'Wrong unowned-target error.');
    }
    check(file_get_contents($target) === 'external', 'Unowned target was deleted.');
    unlink($target);

    foreach ([['HEAD', 302], ['GET', 204], ['GET', 205], ['GET', 304]] as [$method, $status]) {
        $GLOBALS['xcurlFixtureHeaders'] = ["HTTP/1.1 $status Test", 'Location: /next'];
        file_put_contents($target, 'user data');
        $response = (new StreamHttpTransport())->request(
            $method, 'https://example.test/fixture', [], 5, $target,
        );
        check(!$response->targetCreated && $response->bytes === 0, 'Stream no-body claimed target ownership.');
        check(file_get_contents($target) === 'user data', 'Stream no-body changed existing target.');
        unlink($target);
    }

    // The connector injects local socket bytes: no outbound network or TLS fixture needed.
    foreach ([['HEAD', 302], ['GET', 204], ['GET', 205], ['GET', 304]] as [$method, $status]) {
        $pair = null;
        try {
            $transport = new SocketHttpTransport(connector: static function () use ($status, &$pair) {
                $pair = scriptedSocket("HTTP/1.1 $status Test\r\nLocation: /next\r\n\r\n");
                return $pair[1];
            });
            file_put_contents($target, 'user data');
            $response = $transport->request($method, 'https://example.test/start', [], 5, $target);
            check(!$response->targetCreated && $response->bytes === 0, 'Socket no-body claimed target ownership.');
            check(file_get_contents($target) === 'user data', 'Socket no-body changed existing target.');
            unlink($target);
        } finally {
            closeScriptedSocket($pair);
        }
    }
    $pair = null;
    try {
        $socket = new SocketHttpTransport(connector: static function () use (&$pair) {
            $pair = scriptedSocket("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
            return $pair[1];
        });
        $response = $socket->request('GET', 'https://example.test/start', [], 5, $target);
        check($response->targetCreated && $response->bytes === 4, 'Socket did not report owned download.');
        check(file_get_contents($target) === 'data', 'Socket download differs.');
        unlink($target);
    } finally {
        closeScriptedSocket($pair);
    }

    echo "Regression checks passed.\n";
} finally {
    if (file_exists($target) || is_link($target)) {
        unlink($target);
    }
    rmdir($directory);
}
}

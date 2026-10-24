<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Contract;

use AbsolutOffice\XCurl\HttpResponse;

/**
 * Common contract implemented by every xCurl HTTPS transport.
 *
 * Implementations must not follow redirects implicitly and must write a response body
 * directly to $targetPath when one is provided.
 */
interface HttpTransportInterface
{
    /**
     * Executes a single HTTPS request.
     *
     * The transport performs no retries and no per-request transport failover. When
     * $targetPath is provided, the response body is streamed to disk instead of being
     * retained in memory. $requestBody is a raw binary-safe request entity; null means
     * no request entity while an empty string means an explicit zero-byte entity.
     * A transport instance may enforce configured request/response body size limits.
     *
     * timeoutSeconds is a transport safety timeout, not a guaranteed identical wall-clock
     * deadline across backends: cURL applies it as a total/connect cap, PHP streams use
     * the wrapper I/O timeout, and the direct socket applies it to connect and stream I/O.
     *
     * @param string $method HTTP method token; invalid/empty values are rejected at runtime.
     * @param string $url Absolute HTTPS URL; invalid/empty values are rejected at runtime.
     * @param list<string> $headers Request header lines without CR/LF characters.
     * @param int $timeoutSeconds Transport timeout in seconds; values below 1 are rejected.
     * @param string|null $targetPath Optional exclusive response download target.
     * @param string|null $requestBody Optional raw request body. Binary strings are supported.
     *
     * @throws \AbsolutOffice\XCurl\HttpTransportException
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse;
}

<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\HttpResponse;
use InvalidArgumentException;

/**
 * Final response plus request history produced by HttpClient.
 */
final class HttpClientResult
{
    /**
     * @param list<HttpClientAttempt> $history
     */
    public function __construct(
        public readonly HttpResponse $response,
        public readonly string $finalUrl,
        public readonly array $history,
        public readonly int $redirects,
        public readonly int $retries,
    ) {
        if ($finalUrl === '') {
            throw new InvalidArgumentException('Final HTTP client URL cannot be empty.');
        }
        if ($redirects < 0 || $retries < 0) {
            throw new InvalidArgumentException('HTTP client counters cannot be negative.');
        }
    }
}

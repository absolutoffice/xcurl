<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\TransportError;
use InvalidArgumentException;

/**
 * Immutable diagnostic record for one transport invocation performed by HttpClient.
 */
final class HttpClientAttempt
{
    /**
     * Creates one immutable history record for a completed client attempt.
     */
    public function __construct(
        public readonly int $sequence,
        public readonly string $method,
        public readonly string $url,
        public readonly HttpClientAction $action,
        public readonly ?int $statusCode = null,
        public readonly ?TransportError $transportError = null,
        public readonly ?int $delayMilliseconds = null,
        public readonly ?string $nextUrl = null,
    ) {
        if ($sequence < 1) {
            throw new InvalidArgumentException('HTTP client attempt sequence must be at least one.');
        }
        if ($statusCode !== null && ($statusCode < 100 || $statusCode > 599)) {
            throw new InvalidArgumentException('HTTP client attempt status must be between 100 and 599.');
        }
        if ($delayMilliseconds !== null && $delayMilliseconds < 0) {
            throw new InvalidArgumentException('HTTP client attempt delay cannot be negative.');
        }
    }
}

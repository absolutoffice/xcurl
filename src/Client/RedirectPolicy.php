<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use InvalidArgumentException;

/**
 * Manual redirect policy applied above the transport layer.
 */
final class RedirectPolicy
{
    /**
     * Creates redirect limits and cross-origin credential-stripping policy.
     */
    public function __construct(
        public readonly int $maxRedirects = 5,
        public readonly bool $stripAuthorizationOnCrossOrigin = true,
        public readonly bool $stripCookieOnCrossOrigin = true,
    ) {
        if ($maxRedirects < 0) {
            throw new InvalidArgumentException('Maximum redirect count cannot be negative.');
        }
    }

    /**
     * Returns whether a status code is handled as an HTTP redirect.
     */
    public function isRedirectStatus(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    /**
     * Returns the method to use for the next redirect hop.
     */
    public function redirectMethod(string $method, int $statusCode): string
    {
        if ($statusCode === 303 && $method !== 'HEAD') {
            return 'GET';
        }

        return $method;
    }
}

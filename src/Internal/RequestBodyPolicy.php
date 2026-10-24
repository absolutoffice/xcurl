<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

use AbsolutOffice\XCurl\HttpTransportException;
use AbsolutOffice\XCurl\TransportError;
use InvalidArgumentException;

/**
 * Shared request-body size policy used by every transport.
 *
 * @internal
 */
final class RequestBodyPolicy
{
    /**
     * Normalizes an optional request-body limit.
     *
     * @return positive-int|null
     */
    public static function normalizeLimit(?int $maxRequestBytes): ?int
    {
        if ($maxRequestBytes === null) {
            return null;
        }

        if ($maxRequestBytes < 1) {
            throw new InvalidArgumentException('Maximum request size must be at least one byte.');
        }

        return $maxRequestBytes;
    }

    /**
     * Rejects invalid limit configuration or a body that exceeds the configured byte limit.
     */
    public static function assertWithinLimit(?string $body, ?int $maxRequestBytes): void
    {
        $maxRequestBytes = self::normalizeLimit($maxRequestBytes);
        if ($body === null || $maxRequestBytes === null || strlen($body) <= $maxRequestBytes) {
            return;
        }

        throw self::limitExceeded($maxRequestBytes);
    }

    /** @param positive-int $maxRequestBytes */
    public static function limitExceeded(int $maxRequestBytes): HttpTransportException
    {
        return new HttpTransportException(
            sprintf('HTTP request body exceeds the configured limit of %d bytes.', $maxRequestBytes),
            false,
            null,
            TransportError::REQUEST_LIMIT,
        );
    }
}

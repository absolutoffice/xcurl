<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

use AbsolutOffice\XCurl\HttpTransportException;
use AbsolutOffice\XCurl\TransportError;
use InvalidArgumentException;

/**
 * Shared response-body size policy used by every transport.
 *
 * @internal
 */
final class ResponseSizePolicy
{
    /**
     * Normalizes an optional response-body limit.
     *
     * @return positive-int|null
     */
    public static function normalizeLimit(?int $maxResponseBytes): ?int
    {
        if ($maxResponseBytes === null) {
            return null;
        }

        if ($maxResponseBytes < 1) {
            throw new InvalidArgumentException('Maximum response size must be at least one byte.');
        }

        return $maxResponseBytes;
    }

    /**
     * Rejects a known Content-Length before body consumption when it exceeds the limit.
     *
     * @param int|null $contentLength
     * @param positive-int|null $maxResponseBytes
     */
    public static function assertContentLengthWithinLimit(?int $contentLength, ?int $maxResponseBytes): void
    {
        if ($maxResponseBytes === null || $contentLength === null || $contentLength <= $maxResponseBytes) {
            return;
        }

        throw self::limitExceeded($maxResponseBytes);
    }

    /**
     * Rejects an incoming body chunk after transfer framing before storing or writing it.
     *
     * @param int $currentBytes
     * @param int $incomingBytes
     * @param positive-int|null $maxResponseBytes
     */
    public static function assertCanConsume(int $currentBytes, int $incomingBytes, ?int $maxResponseBytes): void
    {
        if ($maxResponseBytes === null) {
            return;
        }

        if ($currentBytes > $maxResponseBytes || $incomingBytes > $maxResponseBytes - $currentBytes) {
            throw self::limitExceeded($maxResponseBytes);
        }
    }

    /**
     * Verifies a body length after a bounded native stream read/copy.
     *
     * @param int $bytes
     * @param positive-int|null $maxResponseBytes
     */
    public static function assertBytesWithinLimit(int $bytes, ?int $maxResponseBytes): void
    {
        if ($maxResponseBytes !== null && $bytes > $maxResponseBytes) {
            throw self::limitExceeded($maxResponseBytes);
        }
    }

    /**
     * Returns max+1 for bounded stream reads, or null when no practical bound is available.
     *
     * @param positive-int|null $maxResponseBytes
     *
     * @return positive-int|null
     */
    public static function streamReadLimit(?int $maxResponseBytes): ?int
    {
        if ($maxResponseBytes === null || $maxResponseBytes === PHP_INT_MAX) {
            return null;
        }

        return $maxResponseBytes + 1;
    }

    /** @param positive-int $maxResponseBytes */
    public static function limitExceeded(int $maxResponseBytes): HttpTransportException
    {
        return new HttpTransportException(
            sprintf('HTTP response body exceeds the configured limit of %d bytes.', $maxResponseBytes),
            false,
            null,
            TransportError::RESPONSE_LIMIT,
        );
    }
}

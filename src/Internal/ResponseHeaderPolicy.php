<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

use AbsolutOffice\XCurl\HttpTransportException;
use AbsolutOffice\XCurl\TransportError;

/**
 * Shared response-header and framing policy used by every transport.
 *
 * @internal
 */
final class ResponseHeaderPolicy
{
    private const TOKEN_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /**
     * Keeps only the final HTTP response block.
     *
     * cURL and PHP streams can expose interim 1xx blocks together with the final
     * response. Returning one final block keeps HttpResponse semantics identical
     * across cURL, streams, and the direct socket transport.
     *
     * @param list<string> $headers
     *
     * @return list<string>
     */
    public static function finalBlock(array $headers): array
    {
        $current = [];
        $seenStatus = false;

        foreach ($headers as $header) {
            if (self::statusFromLine($header) !== null) {
                $current = [$header];
                $seenStatus = true;
                continue;
            }

            if ($seenStatus) {
                $current[] = $header;
            }
        }

        if (!$seenStatus) {
            throw self::protocol('HTTP response did not contain a valid status line.', true);
        }

        self::assertHeaderLines($current);

        return $current;
    }

    /**
     * @param list<string> $headers Final response header block.
     */
    public static function statusCode(array $headers): int
    {
        $status = isset($headers[0]) ? self::statusFromLine($headers[0]) : null;
        if ($status === null || $status < 100 || $status > 599) {
            throw self::protocol('HTTP response did not contain a valid status.', true);
        }

        return $status;
    }

    /**
     * Validates response framing before the body is consumed.
     *
     * @param list<string> $headers Final response header block.
     *
     * @return array{
     *     noBody: bool,
     *     contentLength: int<0, max>|null,
     *     transferEncoding: list<string>|null
     * }
     */
    public static function framing(string $method, int $statusCode, array $headers): array
    {
        if ($statusCode >= 100 && $statusCode < 200) {
            if ($statusCode === 101) {
                throw self::protocol('HTTP 101 Switching Protocols is not supported.');
            }

            throw self::protocol('HTTP response ended with an informational status and no final response.', true);
        }

        $noBody = $method === 'HEAD'
            || $statusCode === 204
            || $statusCode === 205
            || $statusCode === 304;

        $contentLength = self::contentLength($headers);
        $transferEncoding = self::transferEncoding($headers);

        if (!$noBody && $transferEncoding !== null && $contentLength !== null) {
            throw self::protocol('HTTP response contains both Transfer-Encoding and Content-Length.');
        }

        if (!$noBody && $transferEncoding !== null && $transferEncoding !== ['chunked']) {
            throw self::protocol('HTTP response uses an unsupported Transfer-Encoding.');
        }

        return [
            'noBody' => $noBody,
            'contentLength' => $contentLength,
            'transferEncoding' => $transferEncoding,
        ];
    }

    /**
     * Verifies a decoded response body against Content-Length when it applies.
     */
    public static function assertBodyLength(?int $contentLength, int $bytes): void
    {
        if ($contentLength === null) {
            return;
        }

        if ($bytes !== $contentLength) {
            throw new HttpTransportException(
                sprintf('HTTP response body is incomplete: expected %d bytes, received %d.', $contentLength, $bytes),
                true,
                null,
                TransportError::READ,
            );
        }
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    public static function headerValues(array $headers, string $name): array
    {
        $needle = strtolower($name);
        $values = [];

        foreach ($headers as $header) {
            $separator = strpos($header, ':');
            if ($separator === false) {
                continue;
            }

            if (strtolower(trim(substr($header, 0, $separator))) === $needle) {
                $values[] = trim(substr($header, $separator + 1));
            }
        }

        return $values;
    }

    /**
     * @param list<string> $headers
     *
     * @return int<0, max>|null
     */
    private static function contentLength(array $headers): ?int
    {
        $values = self::headerValues($headers, 'content-length');
        if ($values === []) {
            return null;
        }

        $lengths = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                if ($part === '' || !ctype_digit($part)) {
                    throw self::protocol('HTTP response contains an invalid Content-Length.');
                }

                $length = filter_var($part, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
                ]);
                if (!is_int($length)) {
                    throw self::protocol('HTTP response Content-Length is outside the supported range.');
                }
                /** @var int<0, max> $length */
                $lengths[] = $length;
            }
        }

        if (count(array_unique($lengths, SORT_REGULAR)) !== 1) {
            throw self::protocol('HTTP response contains conflicting Content-Length values.');
        }

        $contentLength = $lengths[0];
        /** @var int<0, max> $contentLength */
        return $contentLength;
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>|null
     */
    private static function transferEncoding(array $headers): ?array
    {
        $values = self::headerValues($headers, 'transfer-encoding');
        if ($values === []) {
            return null;
        }

        $tokens = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $token) {
                $token = strtolower(trim($token));
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        if ($tokens === []) {
            throw self::protocol('HTTP response contains an empty Transfer-Encoding.');
        }

        return $tokens;
    }

    /**
     * Validates one response header/trailer field line.
     */
    public static function assertFieldLine(string $header): void
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $header) === 1) {
            throw self::protocol('HTTP response header contains an invalid control character.');
        }

        $separator = strpos($header, ':');
        if ($separator === false || $separator === 0) {
            throw self::protocol('HTTP response contains a malformed header.');
        }

        $name = substr($header, 0, $separator);
        if (preg_match(self::TOKEN_PATTERN, $name) !== 1) {
            throw self::protocol('HTTP response contains an invalid header name.');
        }
    }

    /**
     * @param list<string> $headers
     */
    private static function assertHeaderLines(array $headers): void
    {
        if (isset($headers[0]) && preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $headers[0]) === 1) {
            throw self::protocol('HTTP response status line contains an invalid control character.');
        }

        foreach (array_slice($headers, 1) as $header) {
            self::assertFieldLine($header);
        }
    }

    /**
     * Extracts a three-digit HTTP status code from a status line when present.
     */
    private static function statusFromLine(string $line): ?int
    {
        if (preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d{3})\b/i', $line, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Creates a protocol-classified transport exception.
     */
    private static function protocol(string $message, bool $transient = false): HttpTransportException
    {
        return new HttpTransportException($message, $transient, null, TransportError::PROTOCOL);
    }
}

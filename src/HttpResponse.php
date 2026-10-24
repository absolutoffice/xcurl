<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use InvalidArgumentException;

/**
 * Normalized response returned by every xCurl transport.
 */
final class HttpResponse
{
    /**
     * @param int $statusCode Final HTTP status code (100..599).
     * @param list<string> $headers Final response header block; trailers may be included when exposed by the backend.
     * @param string $body Response body when no target file was supplied.
     * @param int $bytes Number of response-body bytes received or written; must be non-negative.
     * @param bool $targetCreated Whether this attempt exclusively created the target file (not inferred from bytes).
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body = '',
        public readonly int $bytes = 0,
        public readonly bool $targetCreated = false,
    ) {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('HTTP status code must be between 100 and 599.');
        }
        if ($bytes < 0) {
            throw new InvalidArgumentException('HTTP response byte count cannot be negative.');
        }

        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw new InvalidArgumentException('HttpResponse headers must be strings.');
            }
        }

        if ($body !== '' && $bytes !== strlen($body)) {
            throw new InvalidArgumentException('HTTP response byte count must match the in-memory response body.');
        }
    }

    /**
     * Returns the last value for a case-insensitive response header name.
     */
    public function header(string $name): ?string
    {
        $values = $this->headerValues($name);

        return $values === [] ? null : $values[array_key_last($values)];
    }

    /**
     * Returns all values for a case-insensitive response header name.
     *
     * @return list<string>
     */
    public function headerValues(string $name): array
    {
        $needle = strtolower(trim($name));
        if ($needle === '') {
            return [];
        }

        $values = [];
        foreach ($this->headers as $header) {
            $separator = strpos($header, ':');
            if ($separator === false) {
                continue;
            }

            $headerName = strtolower(trim(substr($header, 0, $separator)));
            if ($headerName !== $needle) {
                continue;
            }

            $values[] = trim(substr($header, $separator + 1));
        }

        return $values;
    }

    /**
     * Checks whether a case-insensitive response header is present.
     */
    public function hasHeader(string $name): bool
    {
        return $this->headerValues($name) !== [];
    }
}

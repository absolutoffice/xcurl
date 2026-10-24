<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use AbsolutOffice\XCurl\Internal\RequestBodyPolicy;
use AbsolutOffice\XCurl\Internal\UriPolicy;
use InvalidArgumentException;

/**
 * Shared validation and authority normalization for all xCurl transports.
 *
 * Keeping validation outside individual transports guarantees that the same request
 * is accepted or rejected regardless of which runtime backend is selected.
 *
 * @internal Transport implementation service; not part of the supported consumer API.
 */
final class RequestValidator
{
    private const TOKEN_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /**
     * Validates an HTTPS request and returns normalized request metadata.
     *
     * Content-Length is normalized here so all transports send the same framing. xCurl
     * deliberately does not support request Transfer-Encoding/chunked encoding.
     *
     * @param list<string> $headers
     * @param int|null $maxRequestBytes Optional request-body size limit; values below 1 are invalid.
     *
     * @throws HttpTransportException For invalid request input.
     * @throws \InvalidArgumentException For invalid request-size limit configuration.
     *
     * @return array{
     *     method: non-empty-string,
     *     headers: list<string>,
     *     bodyBytes: int<0, max>,
     *     parts: array{
     *         scheme: 'https',
     *         host: non-empty-string,
     *         port: int<1, 65535>,
     *         path?: string,
     *         query?: string,
     *         fragment?: string
     *     }
     * }
     */
    public static function validate(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $requestBody = null,
        ?int $maxRequestBytes = null,
    ): array {
        if ($timeoutSeconds < 1) {
            throw new HttpTransportException(
                'HTTP timeout must be at least one second.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        // Do not trim the method. HTTP methods are tokens; surrounding whitespace or
        // CR/LF must be rejected rather than silently normalized away.
        $method = strtoupper($method);
        if ($method === '' || preg_match(self::TOKEN_PATTERN, $method) !== 1) {
            throw new HttpTransportException(
                'HTTP method contains invalid characters.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        if ($method === 'CONNECT') {
            throw new HttpTransportException(
                'HTTP CONNECT tunneling is not supported.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        if ($method === 'HEAD' && $requestBody !== null) {
            throw new HttpTransportException(
                'A request body is not supported for HEAD requests.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        RequestBodyPolicy::assertWithinLimit($requestBody, $maxRequestBytes);
        $bodyBytes = $requestBody === null ? 0 : strlen($requestBody);

        try {
            UriPolicy::assertUriReference($url, 'HTTPS URL');
        } catch (InvalidArgumentException $exception) {
            throw new HttpTransportException(
                $exception->getMessage(),
                false,
                $exception,
                TransportError::INVALID_REQUEST,
            );
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new HttpTransportException(
                'Unable to parse HTTPS URL.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;

        if ($scheme !== 'https' || $host === '' || $port < 1 || $port > 65535) {
            throw new HttpTransportException(
                'Only valid HTTPS URLs are accepted.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        try {
            UriPolicy::assertSupportedHost($host);
        } catch (InvalidArgumentException $exception) {
            throw new HttpTransportException(
                $exception->getMessage(),
                false,
                $exception,
                TransportError::INVALID_REQUEST,
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpTransportException(
                'User information in HTTPS URLs is not supported.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        /** @var list<string> $normalizedHeaders */
        $normalizedHeaders = [];
        $declaredContentLength = null;
        $seenContentLength = false;
        $seenHost = false;

        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw new HttpTransportException(
                    'HTTP headers must be strings.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }

            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $header) === 1) {
                throw new HttpTransportException(
                    'HTTP header contains an invalid control character.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }

            $separator = strpos($header, ':');
            if ($separator === false || $separator === 0) {
                throw new HttpTransportException(
                    'HTTP header is malformed.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }

            $name = substr($header, 0, $separator);
            if (preg_match(self::TOKEN_PATTERN, $name) !== 1) {
                throw new HttpTransportException(
                    'HTTP header name is invalid.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }

            $value = trim(substr($header, $separator + 1));
            $lowerName = strtolower($name);
            if ($lowerName === 'transfer-encoding') {
                throw new HttpTransportException(
                    'Request Transfer-Encoding is not supported.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }

            if ($lowerName === 'host') {
                if ($seenHost) {
                    throw new HttpTransportException(
                        'Duplicate request Host headers are not supported.',
                        false,
                        null,
                        TransportError::INVALID_REQUEST,
                    );
                }
                $seenHost = true;
                self::assertHostHeaderValue($value);
            }

            if ($lowerName === 'content-length') {
                if ($seenContentLength) {
                    throw new HttpTransportException(
                        'Duplicate request Content-Length headers are not supported.',
                        false,
                        null,
                        TransportError::INVALID_REQUEST,
                    );
                }
                $seenContentLength = true;

                if ($value === '' || !ctype_digit($value)) {
                    throw new HttpTransportException(
                        'Request Content-Length is invalid.',
                        false,
                        null,
                        TransportError::INVALID_REQUEST,
                    );
                }

                $length = filter_var($value, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
                ]);
                if (!is_int($length)) {
                    throw new HttpTransportException(
                        'Request Content-Length is outside the supported range.',
                        false,
                        null,
                        TransportError::INVALID_REQUEST,
                    );
                }
                /** @var int<0, max> $length */
                $declaredContentLength = $length;
            }

            $normalizedHeaders[] = rtrim($header, " \t");
        }

        if ($requestBody === null) {
            if ($declaredContentLength !== null && $declaredContentLength !== 0) {
                throw new HttpTransportException(
                    'A non-zero request Content-Length requires a request body.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }
        } elseif ($declaredContentLength === null) {
            $normalizedHeaders[] = 'Content-Length: ' . $bodyBytes;
        } elseif ($declaredContentLength !== $bodyBytes) {
            throw new HttpTransportException(
                sprintf(
                    'Request Content-Length does not match the request body: declared %d bytes, actual %d.',
                    $declaredContentLength,
                    $bodyBytes,
                ),
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        $parts['scheme'] = 'https';
        $parts['host'] = $host;
        $parts['port'] = $port;
        /** @var non-empty-string&uppercase-string $method */
        /** @var array{scheme: 'https', host: non-empty-string, port: int<1, 65535>, path?: string, query?: string, fragment?: string} $parts */
        /** @var int<0, max> $bodyBytes */
        return [
            'method' => $method,
            'headers' => $normalizedHeaders,
            'bodyBytes' => $bodyBytes,
            'parts' => $parts,
        ];
    }

    /**
     * Returns the hostname representation expected by TLS peer-name validation.
     */
    private static function assertHostHeaderValue(string $value): void
    {
        if ($value === '') {
            throw new HttpTransportException(
                'Request Host header cannot be empty.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        $host = '';
        $port = null;

        if (str_starts_with($value, '[')) {
            if (preg_match('/^\[([^]]+)\](?::([0-9]+))?$/D', $value, $matches) !== 1) {
                throw new HttpTransportException(
                    'Request Host header is invalid.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }
            $host = '[' . $matches[1] . ']';
            $port = $matches[2] ?? null;
        } elseif (preg_match('/^([^:]+)(?::([0-9]+))?$/D', $value, $matches) === 1) {
            $host = $matches[1];
            $port = $matches[2] ?? null;
        } else {
            throw new HttpTransportException(
                'Request Host header is invalid.',
                false,
                null,
                TransportError::INVALID_REQUEST,
            );
        }

        try {
            UriPolicy::assertSupportedHost($host, 'Request Host header host');
        } catch (InvalidArgumentException $exception) {
            throw new HttpTransportException(
                $exception->getMessage(),
                false,
                $exception,
                TransportError::INVALID_REQUEST,
            );
        }

        if ($port !== null) {
            $validatedPort = filter_var($port, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 65535],
            ]);
            if (!is_int($validatedPort)) {
                throw new HttpTransportException(
                    'Request Host header port is outside the supported range.',
                    false,
                    null,
                    TransportError::INVALID_REQUEST,
                );
            }
        }
    }

    /**
     * Returns the peer host without IPv6 authority brackets.
     */
    public static function peerHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * Returns an HTTP authority host, adding brackets around IPv6 literals.
     */
    public static function authorityHost(string $host): string
    {
        $peerHost = self::peerHost($host);

        return str_contains($peerHost, ':') ? '[' . $peerHost . ']' : $peerHost;
    }
}

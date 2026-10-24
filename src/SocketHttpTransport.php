<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\Internal\PathPolicy;
use AbsolutOffice\XCurl\Internal\RequestBodyPolicy;
use AbsolutOffice\XCurl\Internal\ResponseHeaderPolicy;
use AbsolutOffice\XCurl\Internal\ResponseSizePolicy;
use Closure;

/**
 * Minimal HTTP/1.1-over-TLS transport for restricted runtimes without cURL or HTTPS URL streams.
 */
final class SocketHttpTransport implements HttpTransportInterface
{
    private const MAX_HEADER_BYTES = 65536;
    private const MAX_TRAILER_BYTES = 65536;

    /** @var Closure(string, int, resource): resource|null */
    private readonly ?Closure $connector;

    private readonly ?string $caFile;

    /** @var positive-int|null */
    private readonly ?int $maxResponseBytes;

    /** @var positive-int|null */
    private readonly ?int $maxRequestBytes;

    /**
     * @param string|null $caFile Optional CA bundle used for TLS verification.
     * @param int|null $maxResponseBytes Optional maximum response-body size after transfer framing, in bytes.
     * @param int|null $maxRequestBytes Optional maximum raw request-body size, in bytes.
     * @param callable(string, int, resource): resource|null $connector Internal deterministic connector for tests.
     */
    public function __construct(
        ?string $caFile = null,
        ?int $maxResponseBytes = null,
        ?int $maxRequestBytes = null,
        ?callable $connector = null,
    ) {
        $this->connector = $connector === null ? null : Closure::fromCallable($connector);
        $this->caFile = PathPolicy::normalizeCaFile($caFile);
        $this->maxResponseBytes = ResponseSizePolicy::normalizeLimit($maxResponseBytes);
        $this->maxRequestBytes = RequestBodyPolicy::normalizeLimit($maxRequestBytes);
    }

    /**
     * @internal Runtime capability detail; use isAvailable() for supported diagnostics.
     *
     * @return list<string>
     */
    public static function requiredFunctions(): array
    {
        return [
            'stream_get_transports',
            'stream_context_create',
            'stream_socket_client',
            'stream_socket_enable_crypto',
            'stream_set_timeout',
            'stream_get_meta_data',
            'fopen',
            'fwrite',
            'fread',
            'fgets',
            'feof',
            'fclose',
        ];
    }

    /**
     * @internal Runtime capability detail used by deterministic tests.
     *
     * @param callable(string):bool|null $functionExists
     *
     * @return list<string>
     */
    public static function missingRequiredFunctions(?callable $functionExists = null): array
    {
        $functionExists ??= static fn(string $function): bool => function_exists($function);
        $missing = [];
        foreach (self::requiredFunctions() as $function) {
            if (!$functionExists($function)) {
                $missing[] = $function;
            }
        }
        return $missing;
    }

    /**
     * Returns whether this transport has all runtime capabilities required to operate.
     */
    public static function isAvailable(): bool
    {
        if (!extension_loaded('openssl') || self::missingRequiredFunctions() !== []) {
            return false;
        }

        $transports = stream_get_transports();
        return in_array('tls', $transports, true) || in_array('ssl', $transports, true);
    }

    /** {@inheritDoc} */
    #[\Override]
    public function request(
        string $method,
        string $url,
        array $headers,
        int $timeoutSeconds,
        ?string $targetPath = null,
        ?string $requestBody = null,
    ): HttpResponse {
        if ($this->connector === null && !self::isAvailable()) {
            throw new HttpTransportException(
                'PHP TLS socket transport is not available.',
                false,
                null,
                TransportError::UNAVAILABLE,
            );
        }

        $validated = RequestValidator::validate(
            $method,
            $url,
            $headers,
            $timeoutSeconds,
            $requestBody,
            $this->maxRequestBytes,
        );
        $method = $validated['method'];
        $headers = $validated['headers'];
        $parts = $validated['parts'];
        PathPolicy::assertTransportTargetPath($targetPath);

        $host = $parts['host'];
        $peerHost = RequestValidator::peerHost($host);
        $authorityHost = RequestValidator::authorityHost($host);
        $port = $parts['port'];

        $requestTarget = (string) ($parts['path'] ?? '/');
        if ($requestTarget === '') {
            $requestTarget = '/';
        }
        if (array_key_exists('query', $parts)) {
            $requestTarget .= '?' . $parts['query'];
        }

        $requestHeaders = $this->normalizeRequestHeaders($headers, $authorityHost, $port);
        /** @var array<string, bool|string> $sslOptions */
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $peerHost,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        if ($this->caFile !== null) {
            $sslOptions['cafile'] = $this->caFile;
        }

        $context = stream_context_create([
            'ssl' => $sslOptions,
        ]);

        $remote = sprintf('tls://%s:%d', $authorityHost, $port);
        $socket = $this->connect($remote, $timeoutSeconds, $context);

        $targetCreated = false;
        $success = false;

        try {
            if (!stream_set_timeout($socket, $timeoutSeconds)) {
                throw new HttpTransportException(
                    'Unable to configure TLS socket timeout.',
                    false,
                    null,
                    TransportError::UNAVAILABLE,
                );
            }

            $requestHead = sprintf(
                "%s %s HTTP/1.1\r\n%s\r\n\r\n",
                $method,
                $requestTarget,
                implode("\r\n", $requestHeaders),
            );
            $this->writeAll($socket, $requestHead);
            if ($requestBody !== null && $requestBody !== '') {
                $this->writeAll($socket, $requestBody);
            }

            [$statusCode, $responseHeaders] = $this->readFinalHeaderBlock($socket);
            $responseHeaders = ResponseHeaderPolicy::finalBlock($responseHeaders);
            $framing = ResponseHeaderPolicy::framing($method, $statusCode, $responseHeaders);

            if ($framing['noBody']) {
                $success = true;
                return new HttpResponse($statusCode, $responseHeaders);
            }

            ResponseSizePolicy::assertContentLengthWithinLimit(
                $framing['contentLength'],
                $this->maxResponseBytes,
            );

            $target = null;
            $body = '';
            $bytes = 0;

            if ($targetPath !== null) {
                $target = @fopen($targetPath, 'xb');
                if ($target === false) {
                    throw new HttpTransportException(
                        sprintf('Unable to create HTTP download target: %s', $targetPath),
                        false,
                        null,
                        TransportError::WRITE,
                    );
                }
                $targetCreated = true;
            }

            try {
                if ($framing['transferEncoding'] !== null) {
                    $this->readChunkedBody($socket, $target, $body, $bytes, $responseHeaders);
                    ResponseHeaderPolicy::framing($method, $statusCode, $responseHeaders);
                } elseif ($framing['contentLength'] !== null) {
                    $this->readFixedBody($socket, $framing['contentLength'], $target, $body, $bytes);
                } else {
                    $this->readUntilEof($socket, $target, $body, $bytes);
                }
            } finally {
                if (is_resource($target)) {
                    fclose($target);
                }
            }

            $success = true;
            return new HttpResponse(
                $statusCode,
                $responseHeaders,
                $targetPath === null ? $body : '',
                $bytes,
                $targetCreated,
            );
        } finally {
            fclose($socket);
            if (!$success && $targetCreated && $targetPath !== null) {
                @unlink($targetPath);
            }
        }
    }

    /**
     * @param list<string> $headers
     *
     * @return list<string>
     */
    private function normalizeRequestHeaders(array $headers, string $authorityHost, int $port): array
    {
        $normalized = [];
        $hasHost = false;
        $hasConnection = false;

        foreach ($headers as $header) {
            $trimmed = trim($header);
            $separator = strpos($trimmed, ':');
            $name = strtolower(trim(substr($trimmed, 0, $separator === false ? 0 : $separator)));

            $hasHost = $hasHost || $name === 'host';
            $hasConnection = $hasConnection || $name === 'connection';
            $normalized[] = $trimmed;
        }

        if (!$hasHost) {
            $normalized[] = 'Host: ' . $authorityHost . ($port === 443 ? '' : ':' . $port);
        }
        if (!$hasConnection) {
            $normalized[] = 'Connection: close';
        }

        return $normalized;
    }

    /**
     * @param resource $context
     *
     * @return resource
     */
    private function connect(string $remote, int $timeoutSeconds, $context)
    {
        if ($this->connector !== null) {
            $socket = ($this->connector)($remote, $timeoutSeconds, $context);
            if (!is_resource($socket)) {
                throw new HttpTransportException(
                    'TLS socket test connector failed.',
                    true,
                    null,
                    TransportError::CONNECT,
                );
            }
            return $socket;
        }

        $tcpRemote = 'tcp://' . substr($remote, strlen('tls://'));
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $tcpRemote,
            $errno,
            $error,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new HttpTransportException(
                $error !== ''
                    ? sprintf('TCP connection failed (%d): %s', $errno, $error)
                    : sprintf('TCP connection failed (%d).', $errno),
                true,
                null,
                TransportError::CONNECT,
            );
        }

        if (!stream_set_timeout($socket, $timeoutSeconds)) {
            fclose($socket);
            throw new HttpTransportException(
                'Unable to configure TLS socket timeout before handshake.',
                false,
                null,
                TransportError::UNAVAILABLE,
            );
        }

        $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            $meta = @stream_get_meta_data($socket);
            fclose($socket);

            if ($meta['timed_out'] === true) {
                throw new HttpTransportException(
                    'TLS socket handshake timed out.',
                    true,
                    null,
                    TransportError::TIMEOUT,
                );
            }

            throw new HttpTransportException(
                'TLS socket handshake or certificate verification failed.',
                false,
                null,
                TransportError::TLS,
            );
        }

        return $socket;
    }

    /** @param resource $socket */
    private function writeAll($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                $this->throwReadWriteFailure($socket, 'Unable to write HTTP request to TLS socket.', TransportError::WRITE);
            }
            $offset += $written;
        }
    }

    /**
     * @param resource $socket
     *
     * @return array{0: int, 1: list<string>}
     */
    private function readFinalHeaderBlock($socket): array
    {
        do {
            [$statusCode, $headers] = $this->readHeaderBlock($socket);
        } while ($statusCode >= 100 && $statusCode < 200 && $statusCode !== 101);

        return [$statusCode, $headers];
    }

    /**
     * @param resource $socket
     *
     * @return array{0: int, 1: list<string>}
     */
    private function readHeaderBlock($socket): array
    {
        $statusLine = rtrim($this->readLine($socket, 'HTTP response ended before the status line.'), "\r\n");
        if (preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d{3})\b/i', $statusLine, $matches) !== 1) {
            throw new HttpTransportException(
                'HTTP response did not contain a valid status line.',
                true,
                null,
                TransportError::PROTOCOL,
            );
        }

        $statusCode = (int) $matches[1];
        if ($statusCode < 100 || $statusCode > 599) {
            throw new HttpTransportException(
                'HTTP response status is outside the supported range.',
                false,
                null,
                TransportError::PROTOCOL,
            );
        }

        $headers = [$statusLine];
        $headerBytes = strlen($statusLine);

        while (true) {
            $line = $this->readLine($socket, 'HTTP response ended before headers were complete.');
            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                throw new HttpTransportException(
                    'HTTP response headers exceed the supported size.',
                    false,
                    null,
                    TransportError::PROTOCOL,
                );
            }

            if ($line === "\r\n" || $line === "\n") {
                break;
            }

            $trimmed = rtrim($line, "\r\n");
            $separator = strpos($trimmed, ':');
            if ($separator === false || $separator === 0) {
                throw new HttpTransportException(
                    'HTTP response contains a malformed header.',
                    true,
                    null,
                    TransportError::PROTOCOL,
                );
            }

            $name = substr($trimmed, 0, $separator);
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
                throw new HttpTransportException(
                    'HTTP response contains an invalid header name.',
                    false,
                    null,
                    TransportError::PROTOCOL,
                );
            }
            $headers[] = $trimmed;
        }

        return [$statusCode, $headers];
    }

    /** @param resource $socket */
    private function readLine($socket, string $eofMessage): string
    {
        $line = @fgets($socket, self::MAX_HEADER_BYTES + 2);
        if ($line === false) {
            $this->throwReadWriteFailure($socket, $eofMessage, TransportError::READ);
        }

        if (!str_ends_with($line, "\n") && strlen($line) > self::MAX_HEADER_BYTES) {
            throw new HttpTransportException(
                'HTTP response line exceeds the supported size.',
                false,
                null,
                TransportError::PROTOCOL,
            );
        }

        return $line;
    }

    /**
     * @param resource $socket
     * @param resource|null $target
     */
    private function readFixedBody($socket, int $length, $target, string &$body, int &$bytes): void
    {
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = @fread($socket, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                $this->throwReadWriteFailure(
                    $socket,
                    'HTTP response body ended before Content-Length bytes were received.',
                    TransportError::READ,
                );
            }
            $this->consume($target, $chunk, $body, $bytes);
            $remaining -= strlen($chunk);
        }
    }

    /**
     * @param resource $socket
     * @param resource|null $target
     * @param list<string> $headers
     */
    private function readChunkedBody($socket, $target, string &$body, int &$bytes, array &$headers): void
    {
        while (true) {
            $line = trim($this->readLine($socket, 'Chunked HTTP response ended before the next chunk size.'));
            $sizeToken = trim(explode(';', $line, 2)[0]);

            if ($sizeToken === '' || preg_match('/^[0-9a-fA-F]+$/D', $sizeToken) !== 1) {
                throw new HttpTransportException(
                    'Chunked HTTP response contains an invalid chunk size.',
                    true,
                    null,
                    TransportError::PROTOCOL,
                );
            }

            $size = hexdec($sizeToken);
            if (is_float($size)) {
                if ($size > PHP_INT_MAX) {
                    throw new HttpTransportException(
                        'Chunk size is outside the supported range.',
                        false,
                        null,
                        TransportError::PROTOCOL,
                    );
                }
                $size = (int) $size;
            }

            if ($size === 0) {
                $trailerBytes = 0;
                while (true) {
                    $trailer = $this->readLine($socket, 'Chunked HTTP response ended inside trailers.');
                    $trailerBytes += strlen($trailer);
                    if ($trailerBytes > self::MAX_TRAILER_BYTES) {
                        throw new HttpTransportException(
                            'HTTP response trailers exceed the supported size.',
                            false,
                            null,
                            TransportError::PROTOCOL,
                        );
                    }
                    if ($trailer === "\r\n" || $trailer === "\n") {
                        break;
                    }

                    $trimmed = rtrim($trailer, "\r\n");
                    ResponseHeaderPolicy::assertFieldLine($trimmed);
                    $headers[] = $trimmed;
                }
                return;
            }

            $this->readFixedBody($socket, $size, $target, $body, $bytes);
            if ($this->readExact($socket, 2) !== "\r\n") {
                throw new HttpTransportException(
                    'Chunked HTTP response contains an invalid chunk terminator.',
                    true,
                    null,
                    TransportError::PROTOCOL,
                );
            }
        }
    }

    /**
     * @param resource $socket
     * @param resource|null $target
     */
    private function readUntilEof($socket, $target, string &$body, int &$bytes): void
    {
        while (!feof($socket)) {
            $chunk = @fread($socket, 8192);
            if ($chunk === false) {
                $this->throwReadWriteFailure($socket, 'Unable to read HTTP response body.', TransportError::READ);
            }
            if ($chunk === '') {
                if (feof($socket)) {
                    break;
                }
                $this->throwReadWriteFailure(
                    $socket,
                    'HTTP response body stalled before connection close.',
                    TransportError::READ,
                );
            }
            $this->consume($target, $chunk, $body, $bytes);
        }
    }

    /**
     * @param resource $socket
     * @param positive-int $length
     */
    private function readExact($socket, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $remaining = $length - strlen($data);
            if ($remaining < 1) {
                break;
            }
            $chunk = @fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                $this->throwReadWriteFailure($socket, 'HTTP response ended unexpectedly.', TransportError::READ);
            }
            $data .= $chunk;
        }
        return $data;
    }

    /** @param resource|null $target */
    private function consume($target, string $chunk, string &$body, int &$bytes): void
    {
        $length = strlen($chunk);
        ResponseSizePolicy::assertCanConsume($bytes, $length, $this->maxResponseBytes);

        if ($target === null) {
            $body .= $chunk;
            $bytes += $length;
            return;
        }

        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($target, substr($chunk, $offset));
            if ($written === false || $written === 0) {
                throw new HttpTransportException(
                    'Unable to write HTTP download to disk.',
                    false,
                    null,
                    TransportError::WRITE,
                );
            }
            $offset += $written;
            $bytes += $written;
        }
    }

    /** @param resource $socket */
    private function throwReadWriteFailure($socket, string $fallbackMessage, TransportError $defaultError): never
    {
        $meta = @stream_get_meta_data($socket);
        if ($meta['timed_out'] === true) {
            throw new HttpTransportException(
                'TLS socket request timed out.',
                true,
                null,
                TransportError::TIMEOUT,
            );
        }

        throw new HttpTransportException($fallbackMessage, true, null, $defaultError);
    }
}

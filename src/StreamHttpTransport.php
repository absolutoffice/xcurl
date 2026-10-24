<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\Internal\PathPolicy;
use AbsolutOffice\XCurl\Internal\RequestBodyPolicy;
use AbsolutOffice\XCurl\Internal\ResponseHeaderPolicy;
use AbsolutOffice\XCurl\Internal\ResponseSizePolicy;

/**
 * HTTPS transport backed by the native PHP URL stream wrapper.
 */
final class StreamHttpTransport implements HttpTransportInterface
{
    private readonly ?string $caFile;

    /** @var positive-int|null */
    private readonly ?int $maxResponseBytes;

    /** @var positive-int|null */
    private readonly ?int $maxRequestBytes;

    /**
     * @param string|null $caFile Optional CA bundle used for TLS verification.
     * @param int|null $maxResponseBytes Optional maximum response-body size after transfer framing, in bytes.
     * @param int|null $maxRequestBytes Optional maximum raw request-body size, in bytes.
     */
    public function __construct(
        ?string $caFile = null,
        ?int $maxResponseBytes = null,
        ?int $maxRequestBytes = null,
    ) {
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
            'stream_get_wrappers',
            'stream_context_create',
            'fopen',
            'stream_get_meta_data',
            'stream_get_contents',
            'stream_copy_to_stream',
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
        if (self::missingRequiredFunctions() !== []) {
            return false;
        }

        return filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)
            && in_array('https', stream_get_wrappers(), true);
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
        if (!self::isAvailable()) {
            throw new HttpTransportException(
                'PHP HTTPS stream transport is not available.',
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
        PathPolicy::assertTransportTargetPath($targetPath);

        /** @var array<string, bool|string> $sslOptions */
        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'disable_compression' => true,
        ];
        if ($this->caFile !== null) {
            $sslOptions['cafile'] = $this->caFile;
        }

        /** @var array<string, bool|float|int|string> $httpOptions */
        $httpOptions = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => $timeoutSeconds,
            'protocol_version' => 1.1,
        ];
        if ($requestBody !== null) {
            $httpOptions['content'] = $requestBody;
        }

        $context = stream_context_create([
            'http' => $httpOptions,
            'ssl' => $sslOptions,
        ]);

        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new HttpTransportException(
                'Unable to connect using PHP HTTPS streams.',
                true,
                null,
                TransportError::CONNECT,
            );
        }

        $targetCreated = false;
        $success = false;

        try {
            $meta = stream_get_meta_data($stream);
            $responseHeaders = ResponseHeaderPolicy::finalBlock(
                $this->normalizeHeaders($meta['wrapper_data'] ?? []),
            );
            $statusCode = ResponseHeaderPolicy::statusCode($responseHeaders);
            $framing = ResponseHeaderPolicy::framing($method, $statusCode, $responseHeaders);

            if ($framing['noBody']) {
                $success = true;
                return new HttpResponse($statusCode, $responseHeaders);
            }

            ResponseSizePolicy::assertContentLengthWithinLimit(
                $framing['contentLength'],
                $this->maxResponseBytes,
            );
            $readLimit = ResponseSizePolicy::streamReadLimit($this->maxResponseBytes);

            if ($targetPath === null) {
                $body = $readLimit === null
                    ? stream_get_contents($stream)
                    : stream_get_contents($stream, $readLimit);
                if ($body === false) {
                    throw new HttpTransportException(
                        'Unable to read HTTP response body.',
                        true,
                        null,
                        TransportError::READ,
                    );
                }

                $bytes = strlen($body);
                ResponseSizePolicy::assertBytesWithinLimit($bytes, $this->maxResponseBytes);
                $this->assertNotTimedOut($stream);
                if ($framing['transferEncoding'] === null) {
                    ResponseHeaderPolicy::assertBodyLength($framing['contentLength'], $bytes);
                }
                $success = true;
                return new HttpResponse($statusCode, $responseHeaders, $body, $bytes);
            }

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

            try {
                $bytes = $readLimit === null
                    ? stream_copy_to_stream($stream, $target)
                    : stream_copy_to_stream($stream, $target, $readLimit);
            } finally {
                fclose($target);
            }

            if ($bytes === false) {
                throw new HttpTransportException(
                    'Unable to write HTTP download to disk.',
                    true,
                    null,
                    TransportError::WRITE,
                );
            }

            ResponseSizePolicy::assertBytesWithinLimit($bytes, $this->maxResponseBytes);
            $this->assertNotTimedOut($stream);
            if ($framing['transferEncoding'] === null) {
                ResponseHeaderPolicy::assertBodyLength($framing['contentLength'], $bytes);
            }
            $success = true;
            return new HttpResponse($statusCode, $responseHeaders, '', $bytes, true);
        } finally {
            fclose($stream);
            if (!$success && $targetCreated && $targetPath !== null) {
                @unlink($targetPath);
            }
        }
    }

    /** @param resource $stream */
    private function assertNotTimedOut($stream): void
    {
        $meta = stream_get_meta_data($stream);
        if ($meta['timed_out'] === true) {
            throw new HttpTransportException(
                'HTTPS stream request timed out while reading the response.',
                true,
                null,
                TransportError::TIMEOUT,
            );
        }
    }

    /**
     * @param mixed $headers
     *
     * @return list<string>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (is_string($headers)) {
            return [$headers];
        }
        if (!is_array($headers)) {
            return [];
        }

        $normalized = [];
        foreach ($headers as $header) {
            if (is_string($header)) {
                $normalized[] = $header;
            }
        }
        return $normalized;
    }
}

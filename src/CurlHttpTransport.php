<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use AbsolutOffice\XCurl\Internal\PathPolicy;
use AbsolutOffice\XCurl\Internal\RequestBodyPolicy;
use AbsolutOffice\XCurl\Internal\RequestHeaderPolicy;
use AbsolutOffice\XCurl\Internal\ResponseHeaderPolicy;
use AbsolutOffice\XCurl\Internal\ResponseSizePolicy;

/**
 * HTTPS transport backed by the PHP cURL extension.
 */
final class CurlHttpTransport implements HttpTransportInterface
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
            'curl_init',
            'curl_exec',
            'curl_setopt_array',
            'curl_error',
            'curl_errno',
            'curl_getinfo',
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
        return extension_loaded('curl') && self::missingRequiredFunctions() === [];
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
                'PHP cURL transport is not fully available.',
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

        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpTransportException(
                'Unable to initialize cURL.',
                false,
                null,
                TransportError::UNAVAILABLE,
            );
        }

        $responseHeaders = [];
        $body = '';
        $bytes = 0;
        $target = null;
        $targetCreated = false;
        $success = false;
        $writeFailed = false;
        $responseLimitExceeded = false;

        try {
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

            $curlHeaders = $headers;
            if ($requestBody !== null) {
                // CURLOPT_POSTFIELDS can make libcurl synthesize semantic headers. Suppress
                // those defaults unless the consumer supplied them explicitly so cURL stays
                // aligned with the streams and direct-socket transports.
                if (!RequestHeaderPolicy::hasName($curlHeaders, 'expect')) {
                    $curlHeaders[] = 'Expect:';
                }
                if (!RequestHeaderPolicy::hasName($curlHeaders, 'content-type')) {
                    $curlHeaders[] = 'Content-Type:';
                }
            }

            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $curlHeaders,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed !== '') {
                        $responseHeaders[] = $trimmed;
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (
                    &$body,
                    &$bytes,
                    $target,
                    &$writeFailed,
                    &$responseLimitExceeded,
                ): int {
                    $length = strlen($chunk);

                    try {
                        ResponseSizePolicy::assertCanConsume($bytes, $length, $this->maxResponseBytes);
                    } catch (HttpTransportException) {
                        $responseLimitExceeded = true;
                        return 0;
                    }

                    if ($target === null) {
                        $body .= $chunk;
                        $bytes += $length;
                        return $length;
                    }

                    $offset = 0;
                    while ($offset < $length) {
                        $written = @fwrite($target, substr($chunk, $offset));
                        if ($written === false || $written === 0) {
                            $writeFailed = true;
                            return 0;
                        }
                        $offset += $written;
                        $bytes += $written;
                    }

                    return $length;
                },
                CURLOPT_NOBODY => $method === 'HEAD',
            ];

            if ($requestBody !== null) {
                $options[CURLOPT_POSTFIELDS] = $requestBody;
            }

            $caFile = $this->caFile;
            if ($caFile !== null && $caFile !== '') {
                $options[CURLOPT_CAINFO] = $caFile;
            }

            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = 'HTTPS';
            } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }

            if (!curl_setopt_array($handle, $options)) {
                throw new HttpTransportException(
                    'Unable to configure cURL request.',
                    false,
                    null,
                    TransportError::UNAVAILABLE,
                );
            }

            $ok = curl_exec($handle);
            if ($ok === false) {
                if ($responseLimitExceeded) {
                    $maxResponseBytes = $this->maxResponseBytes;
                    if ($maxResponseBytes === null) {
                        throw new \LogicException('Response limit flag set without a configured limit.');
                    }

                    throw ResponseSizePolicy::limitExceeded($maxResponseBytes);
                }

                if ($writeFailed) {
                    throw new HttpTransportException(
                        'Unable to write HTTP download to disk.',
                        false,
                        null,
                        TransportError::WRITE,
                    );
                }

                if ($responseHeaders !== []) {
                    try {
                        $failedHeaders = ResponseHeaderPolicy::finalBlock($responseHeaders);
                        $failedStatus = ResponseHeaderPolicy::statusCode($failedHeaders);
                        ResponseHeaderPolicy::framing($method, $failedStatus, $failedHeaders);
                    } catch (HttpTransportException $exception) {
                        if ($exception->error === TransportError::PROTOCOL) {
                            throw $exception;
                        }
                    }
                }

                $error = curl_error($handle);
                $errno = curl_errno($handle);
                [$transient, $category] = $this->classifyCurlError($errno);
                throw new HttpTransportException(
                    sprintf('cURL request failed (%d): %s', $errno, $error),
                    $transient,
                    null,
                    $category,
                );
            }

            $responseHeaders = ResponseHeaderPolicy::finalBlock($responseHeaders);
            $statusCode = ResponseHeaderPolicy::statusCode($responseHeaders);
            $curlStatusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($curlStatusCode !== $statusCode) {
                throw new HttpTransportException(
                    'cURL response status does not match the final HTTP header block.',
                    true,
                    null,
                    TransportError::PROTOCOL,
                );
            }

            $framing = ResponseHeaderPolicy::framing($method, $statusCode, $responseHeaders);
            if ($framing['noBody']) {
                $body = '';
                $bytes = 0;
            } else {
                ResponseSizePolicy::assertContentLengthWithinLimit(
                    $framing['contentLength'],
                    $this->maxResponseBytes,
                );
                ResponseSizePolicy::assertBytesWithinLimit($bytes, $this->maxResponseBytes);

                if ($framing['transferEncoding'] === null) {
                    ResponseHeaderPolicy::assertBodyLength($framing['contentLength'], $bytes);
                }
            }

            $success = true;
            return new HttpResponse($statusCode, $responseHeaders, $target === null ? $body : '', $bytes, $targetCreated);
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            unset($handle);

            if (!$success && $targetCreated && $targetPath !== null) {
                @unlink($targetPath);
            }
        }
    }

    /** @return array{0:bool,1:TransportError} */
    private function classifyCurlError(int $errno): array
    {
        $timeout = self::curlErrorCodes(['CURLE_OPERATION_TIMEDOUT']);
        $dns = self::curlErrorCodes(['CURLE_COULDNT_RESOLVE_PROXY', 'CURLE_COULDNT_RESOLVE_HOST']);
        $connect = self::curlErrorCodes(['CURLE_COULDNT_CONNECT']);
        $readWrite = self::curlErrorCodes([
            'CURLE_SEND_ERROR',
            'CURLE_RECV_ERROR',
            'CURLE_GOT_NOTHING',
            'CURLE_PARTIAL_FILE',
        ]);
        $tls = self::curlErrorCodes([
            'CURLE_SSL_CONNECT_ERROR',
            'CURLE_PEER_FAILED_VERIFICATION',
            'CURLE_SSL_CERTPROBLEM',
            'CURLE_SSL_CIPHER',
            'CURLE_SSL_CACERT_BADFILE',
        ]);
        // libcurl CURLcode 60 is CURLE_PEER_FAILED_VERIFICATION. Some PHP
        // builds expose the code without defining the corresponding constant.
        $tls[] = 60;

        if (in_array($errno, $timeout, true)) {
            return [true, TransportError::TIMEOUT];
        }
        if (in_array($errno, $dns, true)) {
            return [true, TransportError::DNS];
        }
        if (in_array($errno, $connect, true)) {
            return [true, TransportError::CONNECT];
        }
        if (in_array($errno, $tls, true)) {
            return [false, TransportError::TLS];
        }
        if (in_array($errno, $readWrite, true)) {
            return [true, TransportError::READ];
        }

        return [false, TransportError::UNKNOWN];
    }

    /**
     * Resolve cURL error constants defensively because availability varies across
     * PHP/libcurl builds and aliases can disappear between versions.
     *
     * @param list<non-empty-string> $names
     *
     * @return list<int>
     */
    private static function curlErrorCodes(array $names): array
    {
        $codes = [];
        foreach ($names as $name) {
            if (!defined($name)) {
                continue;
            }

            $value = constant($name);
            if (is_int($value)) {
                $codes[] = $value;
            }
        }

        return array_values(array_unique($codes));
    }
}

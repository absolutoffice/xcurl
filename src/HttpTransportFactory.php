<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use AbsolutOffice\XCurl\Contract\HttpTransportInterface;
use RuntimeException;

/**
 * Selects one HTTPS transport from the capabilities available in the current PHP SAPI.
 *
 * Default selection order is cURL, HTTPS streams, then direct TLS socket. Selection
 * is an availability fallback only; request failures do not silently switch transports.
 *
 * createStrict() is available for consumers that require strict response-framing
 * checks. It selects cURL and then the direct socket transport, deliberately skipping
 * PHP HTTPS streams because the native wrapper hides Transfer-Encoding details.
 */
final class HttpTransportFactory
{
    public const TRANSPORT_CURL = 'curl';
    public const TRANSPORT_STREAMS = 'streams';
    public const TRANSPORT_SOCKET = 'socket';

    /**
     * Returns the transport that would currently be selected, or null if none is usable.
     *
     * @return self::TRANSPORT_CURL|self::TRANSPORT_SOCKET|self::TRANSPORT_STREAMS|null
     */
    public static function selectedTransportName(): ?string
    {
        return self::selectTransportName(
            CurlHttpTransport::isAvailable(),
            StreamHttpTransport::isAvailable(),
            SocketHttpTransport::isAvailable(),
        );
    }

    /**
     * Pure selection helper used by diagnostics and tests.
     *
     * @internal Use selectedTransportName() or diagnostics() from consumer code.
     *
     * @return self::TRANSPORT_CURL|self::TRANSPORT_SOCKET|self::TRANSPORT_STREAMS|null
     */
    public static function selectTransportName(
        bool $curlAvailable,
        bool $streamsAvailable,
        bool $socketAvailable,
    ): ?string {
        if ($curlAvailable) {
            return self::TRANSPORT_CURL;
        }
        if ($streamsAvailable) {
            return self::TRANSPORT_STREAMS;
        }
        if ($socketAvailable) {
            return self::TRANSPORT_SOCKET;
        }

        return null;
    }

    /**
     * Returns the strict-framing transport that would currently be selected.
     *
     * PHP HTTPS streams are intentionally excluded because the wrapper can remove
     * Transfer-Encoding before userland sees the response headers.
     *
     * @return self::TRANSPORT_CURL|self::TRANSPORT_SOCKET|null
     */
    public static function selectedStrictTransportName(): ?string
    {
        return self::selectStrictTransportName(
            CurlHttpTransport::isAvailable(),
            SocketHttpTransport::isAvailable(),
        );
    }

    /**
     * Pure strict-framing selection helper used by diagnostics and tests.
     *
     * @internal Use selectedStrictTransportName() or diagnostics() from consumer code.
     *
     * @return self::TRANSPORT_CURL|self::TRANSPORT_SOCKET|null
     */
    public static function selectStrictTransportName(bool $curlAvailable, bool $socketAvailable): ?string
    {
        if ($curlAvailable) {
            return self::TRANSPORT_CURL;
        }
        if ($socketAvailable) {
            return self::TRANSPORT_SOCKET;
        }

        return null;
    }

    /**
     * Reports transport availability for the current SAPI/runtime.
     *
     * Apache/FPM and CLI can have different php.ini files and loaded extensions even
     * when PHP_VERSION is identical, so diagnostics should be checked in each SAPI.
     *
     * @return array{
     *     curl: bool,
     *     streams: bool,
     *     socket: bool,
     *     selected: 'curl'|'socket'|'streams'|null,
     *     selectedStrict: 'curl'|'socket'|null,
     *     strictFraming: array{curl: true, streams: false, socket: true}
     * }
     */
    public static function diagnostics(): array
    {
        $curl = CurlHttpTransport::isAvailable();
        $streams = StreamHttpTransport::isAvailable();
        $socket = SocketHttpTransport::isAvailable();

        return [
            self::TRANSPORT_CURL => $curl,
            self::TRANSPORT_STREAMS => $streams,
            self::TRANSPORT_SOCKET => $socket,
            'selected' => self::selectTransportName($curl, $streams, $socket),
            'selectedStrict' => self::selectStrictTransportName($curl, $socket),
            'strictFraming' => [
                self::TRANSPORT_CURL => true,
                self::TRANSPORT_STREAMS => false,
                self::TRANSPORT_SOCKET => true,
            ],
        ];
    }

    /**
     * Creates the preferred transport for the current PHP runtime.
     *
     * @param int|null $maxResponseBytes Optional maximum response-body size after transfer framing, in bytes.
     * @param int|null $maxRequestBytes Optional maximum raw request-body size, in bytes.
     *
     * @throws RuntimeException When no supported HTTPS transport is available.
     */
    public static function create(
        ?int $maxResponseBytes = null,
        ?int $maxRequestBytes = null,
    ): HttpTransportInterface {
        return match (self::selectedTransportName()) {
            self::TRANSPORT_CURL => new CurlHttpTransport(null, $maxResponseBytes, $maxRequestBytes),
            self::TRANSPORT_STREAMS => new StreamHttpTransport(null, $maxResponseBytes, $maxRequestBytes),
            self::TRANSPORT_SOCKET => new SocketHttpTransport(null, $maxResponseBytes, $maxRequestBytes),
            default => throw new RuntimeException(
                'No usable HTTPS transport is available: cURL, HTTPS streams, or outbound TLS sockets are required.',
            ),
        };
    }

    /**
     * Creates the preferred transport with strict response-framing guarantees.
     *
     * This mode deliberately skips StreamHttpTransport. TLS verification remains
     * enabled exactly as in create(); only the response-framing capability differs.
     *
     * @param int|null $maxResponseBytes Optional maximum response-body size after transfer framing, in bytes.
     * @param int|null $maxRequestBytes Optional maximum raw request-body size, in bytes.
     *
     * @throws RuntimeException When neither cURL nor the direct TLS socket is available.
     */
    public static function createStrict(
        ?int $maxResponseBytes = null,
        ?int $maxRequestBytes = null,
    ): HttpTransportInterface {
        return match (self::selectedStrictTransportName()) {
            self::TRANSPORT_CURL => new CurlHttpTransport(null, $maxResponseBytes, $maxRequestBytes),
            self::TRANSPORT_SOCKET => new SocketHttpTransport(null, $maxResponseBytes, $maxRequestBytes),
            default => throw new RuntimeException(
                'No strict-framing HTTPS transport is available: cURL or outbound TLS sockets are required.',
            ),
        };
    }

    /**
     * Creates a specific transport for deterministic tests and diagnostics.
     *
     * Normal application code should use create().
     *
     * @param 'curl'|'socket'|'streams'|string $transport
     * @param int|null $maxResponseBytes Optional maximum response-body size after transfer framing, in bytes.
     * @param int|null $maxRequestBytes Optional maximum raw request-body size, in bytes.
     *
     * @throws RuntimeException When the requested transport is unknown or unavailable.
     */
    public static function createNamed(
        string $transport,
        ?int $maxResponseBytes = null,
        ?int $maxRequestBytes = null,
    ): HttpTransportInterface {
        return match (strtolower(trim($transport))) {
            self::TRANSPORT_CURL => CurlHttpTransport::isAvailable()
                ? new CurlHttpTransport(null, $maxResponseBytes, $maxRequestBytes)
                : throw new RuntimeException('Requested cURL transport is not available.'),
            self::TRANSPORT_STREAMS => StreamHttpTransport::isAvailable()
                ? new StreamHttpTransport(null, $maxResponseBytes, $maxRequestBytes)
                : throw new RuntimeException('Requested HTTPS streams transport is not available.'),
            self::TRANSPORT_SOCKET => SocketHttpTransport::isAvailable()
                ? new SocketHttpTransport(null, $maxResponseBytes, $maxRequestBytes)
                : throw new RuntimeException('Requested TLS socket transport is not available.'),
            default => throw new RuntimeException(sprintf('Unknown HTTP transport: %s', $transport)),
        };
    }
}

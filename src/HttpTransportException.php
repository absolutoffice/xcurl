<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use RuntimeException;
use Throwable;

/**
 * Transport-layer failure with retry-oriented metadata.
 */
final class HttpTransportException extends RuntimeException
{
    /**
     * @param bool $transient Whether retrying the same transport may reasonably succeed.
     * @param TransportError $error Stable machine-readable failure category.
     */
    public function __construct(
        string $message,
        public readonly bool $transient,
        ?Throwable $previous = null,
        public readonly TransportError $error = TransportError::UNKNOWN,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

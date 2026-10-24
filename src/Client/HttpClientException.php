<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use RuntimeException;
use Throwable;

/**
 * Client-level failure with the attempts completed before the error.
 */
final class HttpClientException extends RuntimeException
{
    /**
     * @param list<HttpClientAttempt> $history
     */
    public function __construct(
        string $message,
        public readonly ClientError $error,
        public readonly array $history = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

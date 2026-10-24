<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

use InvalidArgumentException;

/**
 * Immutable high-level HTTP request value object.
 *
 * The transport layer remains scalar for implementor simplicity. Client\HttpClient::send()
 * accepts this object so consuming applications do not need to grow positional argument
 * lists as client features evolve.
 */
final class HttpRequest
{
    /** @var list<string> */
    public readonly array $headers;

    /**
     * @param array<int, string> $headers Request header lines without CR/LF characters; keys are normalized.
     * @param string|null $body Optional raw binary-safe request body.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        array $headers = [],
        public readonly ?string $body = null,
    ) {
        foreach ($headers as $header) {
            if (!is_string($header)) {
                throw new InvalidArgumentException('HttpRequest headers must be strings.');
            }
        }

        $this->headers = array_values($headers);
    }
}

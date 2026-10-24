<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\Internal\PathPolicy;
use InvalidArgumentException;

/**
 * Immutable execution options for HttpClient::send().
 *
 * timeoutSeconds is the per-transport safety timeout. deadlineSeconds, when supplied,
 * caps the whole client operation across retries, redirects, and sleeps.
 */
final class RequestOptions
{
    /**
     * Creates immutable per-operation execution options.
     */
    public function __construct(
        public readonly int $timeoutSeconds,
        public readonly ?string $targetPath = null,
        public readonly ?int $deadlineSeconds = null,
    ) {
        if ($timeoutSeconds < 1) {
            throw new InvalidArgumentException('HTTP client timeout must be at least one second.');
        }
        if ($deadlineSeconds !== null && $deadlineSeconds < 1) {
            throw new InvalidArgumentException('HTTP client deadline must be at least one second.');
        }

        PathPolicy::assertOptionTargetPath($targetPath);
    }
}

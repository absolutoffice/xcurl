<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Contract;

/**
 * Clock abstraction used by client-level deadline and Retry-After policy.
 */
interface ClockInterface
{
    /**
     * Returns a monotonic timestamp in seconds.
     */
    public function monotonicSeconds(): float;

    /**
     * Returns the current Unix timestamp in whole seconds.
     */
    public function unixTime(): int;
}

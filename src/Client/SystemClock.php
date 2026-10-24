<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\Contract\ClockInterface;

/**
 * Native system clock implementation.
 *
 * @internal Default implementation detail; inject the public contract when customization is required.
 */
final class SystemClock implements ClockInterface
{
    /** {@inheritDoc} */
    #[\Override]
    public function monotonicSeconds(): float
    {
        return (float) hrtime(true) / 1_000_000_000;
    }

    /** {@inheritDoc} */
    #[\Override]
    public function unixTime(): int
    {
        return time();
    }
}

<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\Contract\SleeperInterface;
use InvalidArgumentException;

/**
 * Native system sleeper implementation.
 *
 * @internal Default implementation detail; inject the public contract when customization is required.
 */
final class SystemSleeper implements SleeperInterface
{
    /** {@inheritDoc} */
    #[\Override]
    public function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException('Sleep duration cannot be negative.');
        }
        if ($milliseconds === 0) {
            return;
        }
        if ($milliseconds > intdiv(PHP_INT_MAX, 1_000)) {
            throw new InvalidArgumentException('Sleep duration is too large.');
        }

        $microseconds = $milliseconds * 1_000;
        usleep($microseconds);
    }
}

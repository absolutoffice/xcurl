<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Contract;

/**
 * Sleep abstraction used by retry policy.
 */
interface SleeperInterface
{
    /**
     * Sleeps for the requested number of milliseconds.
     */
    public function sleepMilliseconds(int $milliseconds): void;
}

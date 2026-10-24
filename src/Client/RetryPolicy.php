<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

use AbsolutOffice\XCurl\Contract\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Retry policy for transient transport errors and retryable HTTP status codes.
 */
final class RetryPolicy
{
    private const TOKEN_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /** @var list<int> */
    private readonly array $backoffMilliseconds;

    /** @var list<string> */
    private readonly array $methods;

    /** @var list<int> */
    private readonly array $statusCodes;

    /**
     * @param list<int> $backoffMilliseconds Backoff for retry #1, #2, ...; the last value is reused when needed.
     * @param list<string> $methods Methods eligible for automatic retry.
     * @param list<int> $statusCodes Explicit retryable status codes in addition to optional 5xx handling.
     */
    public function __construct(
        public readonly int $maxRetries = 2,
        array $backoffMilliseconds = [1_000, 3_000],
        array $methods = ['GET', 'HEAD'],
        array $statusCodes = [408, 429],
        public readonly bool $retryServerErrors = true,
        public readonly int $maxRetryAfterSeconds = 60,
    ) {
        if ($maxRetries < 0) {
            throw new InvalidArgumentException('Maximum retry count cannot be negative.');
        }
        if ($maxRetryAfterSeconds < 0) {
            throw new InvalidArgumentException('Maximum Retry-After value cannot be negative.');
        }

        foreach ($backoffMilliseconds as $delay) {
            if (!is_int($delay) || $delay < 0) {
                throw new InvalidArgumentException('Retry backoff values must be non-negative integers.');
            }
        }

        $normalizedMethods = [];
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match(self::TOKEN_PATTERN, $method) !== 1) {
                throw new InvalidArgumentException('Retry methods must be valid HTTP method tokens.');
            }
            $normalizedMethods[] = strtoupper($method);
        }

        foreach ($statusCodes as $statusCode) {
            if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
                throw new InvalidArgumentException('Retry status codes must be integers between 100 and 599.');
            }
        }

        $this->backoffMilliseconds = array_values($backoffMilliseconds);
        $this->methods = array_values(array_unique($normalizedMethods));
        $this->statusCodes = array_values(array_unique($statusCodes));
    }

    /**
     * Returns whether the HTTP method is eligible for automatic retry.
     */
    public function allowsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * Returns whether an HTTP response status is retryable by policy.
     */
    public function shouldRetryStatus(int $statusCode): bool
    {
        if (in_array($statusCode, $this->statusCodes, true)) {
            return true;
        }

        return $this->retryServerErrors && $statusCode >= 500 && $statusCode <= 599;
    }

    /**
     * Returns the delay for a one-based retry number.
     */
    public function delayMilliseconds(int $retryNumber, ?string $retryAfter, ClockInterface $clock): int
    {
        if ($retryNumber < 1) {
            throw new InvalidArgumentException('Retry number must be at least one.');
        }

        if ($retryAfter !== null) {
            $retryAfterSeconds = $this->parseRetryAfterSeconds($retryAfter, $clock);
            if ($retryAfterSeconds !== null) {
                $retryAfterSeconds = min(
                    $retryAfterSeconds,
                    $this->maxRetryAfterSeconds,
                    intdiv(PHP_INT_MAX, 1_000),
                );

                return $retryAfterSeconds * 1_000;
            }
        }

        if ($this->backoffMilliseconds === []) {
            return 0;
        }

        $index = min($retryNumber - 1, count($this->backoffMilliseconds) - 1);

        return $this->backoffMilliseconds[$index];
    }

    /**
     * Parses Retry-After as delta-seconds or an HTTP date.
     */
    private function parseRetryAfterSeconds(string $value, ClockInterface $clock): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            $seconds = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
            ]);

            return is_int($seconds) ? $seconds : null;
        }

        $timezone = new DateTimeZone('GMT');
        $candidates = [
            ['D, d M Y H:i:s \\G\\M\\T', $value],
            ['l, d-M-y H:i:s \\G\\M\\T', $value],
            ['D M j H:i:s Y', preg_replace('/\\s+/', ' ', $value) ?? $value],
        ];

        foreach ($candidates as [$format, $candidate]) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $candidate, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date === false) {
                continue;
            }
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return max(0, $date->getTimestamp() - $clock->unixTime());
        }

        return null;
    }
}

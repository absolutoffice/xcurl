<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

use AbsolutOffice\XCurl\HttpTransportException;
use AbsolutOffice\XCurl\TransportError;
use InvalidArgumentException;

/**
 * Shared filesystem path validation for public xCurl configuration and targets.
 *
 * @internal
 */
final class PathPolicy
{
    /**
     * Normalizes and validates an optional CA bundle path.
     */
    public static function normalizeCaFile(?string $caFile): ?string
    {
        if ($caFile === null) {
            return null;
        }

        self::assertPathString($caFile, 'CA bundle path');

        return $caFile;
    }

    /**
     * Validates a target path supplied through client request options.
     */
    public static function assertOptionTargetPath(?string $targetPath): void
    {
        if ($targetPath === null) {
            return;
        }

        self::assertPathString($targetPath, 'HTTP target path');
    }

    /**
     * Validates a target path at the transport boundary and maps failures to transport errors.
     */
    public static function assertTransportTargetPath(?string $targetPath): void
    {
        if ($targetPath === null) {
            return;
        }

        try {
            self::assertPathString($targetPath, 'HTTP target path');
        } catch (InvalidArgumentException $exception) {
            throw new HttpTransportException(
                $exception->getMessage(),
                false,
                $exception,
                TransportError::INVALID_REQUEST,
            );
        }
    }

    /**
     * Rejects empty filesystem paths and paths containing NUL bytes.
     */
    private static function assertPathString(string $path, string $label): void
    {
        if ($path === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $label));
        }

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException(sprintf('%s cannot contain NUL bytes.', $label));
        }
    }
}

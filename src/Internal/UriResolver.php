<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

use InvalidArgumentException;

/**
 * Minimal RFC 3986-style resolver for HTTPS redirect targets.
 *
 * @internal
 */
final class UriResolver
{
    /**
     * Resolves a redirect Location reference against an absolute HTTPS base URL.
     */
    public static function resolve(string $baseUrl, string $location): string
    {
        UriPolicy::assertUriReference($baseUrl, 'Redirect base URL');
        UriPolicy::assertUriReference($location, 'Redirect Location', true);

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new InvalidArgumentException('Redirect base URL is invalid.');
        }

        $reference = parse_url($location);
        if ($reference === false) {
            throw new InvalidArgumentException('Redirect Location is invalid.');
        }
        if (isset($reference['scheme'])) {
            $target = $reference;
        } elseif (str_starts_with($location, '//')) {
            $target = $reference;
            $target['scheme'] = $base['scheme'];
        } else {
            $target = $base;
            unset($target['fragment'], $target['user'], $target['pass']);

            if (isset($reference['host'])) {
                $target = $reference;
                $target['scheme'] = $base['scheme'];
            } else {
                if (array_key_exists('path', $reference)) {
                    $referencePath = (string) $reference['path'];
                    if ($referencePath === '') {
                        $target['path'] = (string) ($base['path'] ?? '/');
                    } elseif (str_starts_with($referencePath, '/')) {
                        $target['path'] = self::removeDotSegments($referencePath);
                    } else {
                        $target['path'] = self::removeDotSegments(
                            self::mergePaths((string) ($base['path'] ?? '/'), $referencePath),
                        );
                    }
                    unset($target['query']);
                }

                if (array_key_exists('query', $reference)) {
                    $target['query'] = $reference['query'];
                }
            }
        }

        unset($target['fragment']);

        $scheme = strtolower((string) ($target['scheme'] ?? ''));
        $host = (string) ($target['host'] ?? '');
        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Redirect target must be an absolute HTTPS URL after resolution.');
        }
        UriPolicy::assertSupportedHost($host, 'Redirect target host');
        if (isset($target['user']) || isset($target['pass'])) {
            throw new InvalidArgumentException('Redirect target cannot contain user information.');
        }

        $port = isset($target['port']) ? (int) $target['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidArgumentException('Redirect target contains an invalid port.');
        }

        $path = (string) ($target['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $path = self::removeDotSegments($path);

        $authorityHost = str_contains($host, ':') && !str_starts_with($host, '[')
            ? '[' . $host . ']'
            : $host;

        $url = 'https://' . $authorityHost;
        if ($port !== null && $port !== 443) {
            $url .= ':' . $port;
        }
        $url .= $path;

        if (array_key_exists('query', $target)) {
            $url .= '?' . (string) $target['query'];
        }

        return $url;
    }

    /**
     * Returns whether two URLs share the same normalized scheme, host, and port.
     */
    public static function sameOrigin(string $leftUrl, string $rightUrl): bool
    {
        $left = self::origin($leftUrl);
        $right = self::origin($rightUrl);

        return $left === $right;
    }

    /**
     * @return array{scheme: string, host: string, port: int}
     */
    private static function origin(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Unable to parse URL origin.');
        }

        return [
            'scheme' => strtolower((string) ($parts['scheme'] ?? '')),
            'host' => strtolower((string) ($parts['host'] ?? '')),
            'port' => isset($parts['port']) ? (int) $parts['port'] : 443,
        ];
    }

    /**
     * Merges a relative redirect path with the directory of the base path.
     */
    private static function mergePaths(string $basePath, string $relativePath): string
    {
        if ($basePath === '' || !str_starts_with($basePath, '/')) {
            $basePath = '/' . ltrim($basePath, '/');
        }

        $separator = strrpos($basePath, '/');
        $directory = $separator === false ? '/' : substr($basePath, 0, $separator + 1);

        return $directory . $relativePath;
    }

    /**
     * Removes RFC 3986 dot segments from a URI path.
     */
    private static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';

        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
                continue;
            }
            if (str_starts_with($input, './')) {
                $input = substr($input, 2);
                continue;
            }
            if (str_starts_with($input, '/./')) {
                $input = '/' . substr($input, 3);
                continue;
            }
            if ($input === '/.') {
                $input = '/';
                continue;
            }
            if (str_starts_with($input, '/../')) {
                $input = '/' . substr($input, 4);
                $output = self::removeLastPathSegment($output);
                continue;
            }
            if ($input === '/..') {
                $input = '/';
                $output = self::removeLastPathSegment($output);
                continue;
            }
            if ($input === '.' || $input === '..') {
                $input = '';
                continue;
            }

            $segmentLength = self::firstPathSegmentLength($input);
            $output .= substr($input, 0, $segmentLength);
            $input = substr($input, $segmentLength);
        }

        return $output;
    }

    /**
     * Returns the byte length of the first path segment in the remaining input.
     */
    private static function firstPathSegmentLength(string $path): int
    {
        if (str_starts_with($path, '/')) {
            $nextSlash = strpos($path, '/', 1);

            return $nextSlash === false ? strlen($path) : $nextSlash;
        }

        $nextSlash = strpos($path, '/');

        return $nextSlash === false ? strlen($path) : $nextSlash;
    }

    /**
     * Removes the final segment from an accumulated URI path.
     */
    private static function removeLastPathSegment(string $path): string
    {
        $slash = strrpos($path, '/');
        if ($slash === false) {
            return '';
        }

        return substr($path, 0, $slash);
    }
}

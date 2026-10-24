<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

/**
 * Header transformations required by xCurl request/client policy.
 *
 * @internal
 */
final class RequestHeaderPolicy
{
    /**
     * @param list<string> $headers
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function withoutNames(array $headers, array $names): array
    {
        $needles = array_map('strtolower', $names);
        $result = [];

        foreach ($headers as $header) {
            $separator = strpos($header, ':');
            if ($separator === false) {
                $result[] = $header;
                continue;
            }

            $name = strtolower(trim(substr($header, 0, $separator)));
            if (in_array($name, $needles, true)) {
                continue;
            }

            $result[] = $header;
        }

        return $result;
    }

    /**
     * @param list<string> $headers
     */
    public static function hasName(array $headers, string $name): bool
    {
        $needle = strtolower($name);

        foreach ($headers as $header) {
            $separator = strpos($header, ':');
            if ($separator === false) {
                continue;
            }

            if (strtolower(trim(substr($header, 0, $separator))) === $needle) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Internal;

use InvalidArgumentException;

/**
 * Shared URI syntax policy for transport requests and client redirects.
 *
 * xCurl deliberately accepts URI syntax, not raw IRIs. Non-ASCII characters must
 * be percent-encoded by the caller so cURL, PHP streams, and the direct socket
 * transport see the same byte sequence.
 *
 * @internal
 */
final class UriPolicy
{
    private const URI_REFERENCE_PATTERN = "/^[A-Za-z0-9\\-._~:\\/?#\\[\\]@!$&'()*+,;=%]*$/D";

    /**
     * Validates an ASCII RFC 3986 URI reference and its percent-encoding.
     */
    public static function assertUriReference(string $value, string $label, bool $allowEmpty = false): void
    {
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $label));
        }

        if (preg_match(self::URI_REFERENCE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf('%s must use ASCII RFC 3986 URI characters; non-ASCII data must be percent-encoded.', $label),
            );
        }

        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('%s contains malformed percent-encoding.', $label));
        }
    }

    /**
     * Validates a DNS name, IPv4 address, or IPv6 literal accepted by xCurl.
     */
    public static function assertSupportedHost(string $host, string $label = 'HTTPS URL host'): void
    {
        $peerHost = $host;
        if (str_starts_with($peerHost, '[') && str_ends_with($peerHost, ']')) {
            $peerHost = substr($peerHost, 1, -1);
        }

        if ($peerHost === '') {
            throw new InvalidArgumentException(sprintf('%s cannot be empty.', $label));
        }

        if (filter_var($peerHost, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        if (filter_var($peerHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf('%s must be a valid ASCII DNS name, IPv4 address, or IPv6 literal.', $label),
        );
    }
}

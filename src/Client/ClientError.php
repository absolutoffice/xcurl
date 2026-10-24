<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

/**
 * Stable client-level failure categories.
 */
enum ClientError: string
{
    case INVALID_REDIRECT = 'invalid_redirect';
    case REDIRECT_LIMIT = 'redirect_limit';
    case DEADLINE = 'deadline';
    case TARGET_CLEANUP = 'target_cleanup';
    case TRANSPORT = 'transport';
}

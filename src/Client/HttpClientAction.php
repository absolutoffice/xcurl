<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl\Client;

/**
 * Action taken after one client-level transport attempt.
 */
enum HttpClientAction: string
{
    case RETURNED = 'returned';
    case REDIRECTED = 'redirected';
    case RETRIED = 'retried';
    case FAILED = 'failed';
}

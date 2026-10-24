<?php

declare(strict_types=1);

namespace AbsolutOffice\XCurl;

/**
 * Stable transport-error categories for retry policy, logging, and diagnostics.
 */
enum TransportError: string
{
    case DNS = 'dns';
    case CONNECT = 'connect';
    case TLS = 'tls';
    case TIMEOUT = 'timeout';
    case READ = 'read';
    case WRITE = 'write';
    case PROTOCOL = 'protocol';
    case RESPONSE_LIMIT = 'response_limit';
    case REQUEST_LIMIT = 'request_limit';
    case INVALID_REQUEST = 'invalid_request';
    case UNAVAILABLE = 'unavailable';
    case UNKNOWN = 'unknown';
}

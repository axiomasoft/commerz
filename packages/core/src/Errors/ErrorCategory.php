<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
enum ErrorCategory: string
{
    case Validation = 'validation';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case Limit = 'limit';
    case Unsupported = 'unsupported';
    case Configuration = 'configuration';
    case Integration = 'integration';
}

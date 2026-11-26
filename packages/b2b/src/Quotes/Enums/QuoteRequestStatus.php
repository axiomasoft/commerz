<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum QuoteRequestStatus: string
{
    case Submitted = 'submitted';
    case InReview = 'in_review';
    case Quoted = 'quoted';
    case RenegotiationRequested = 'renegotiation_requested';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case Canceled = 'canceled';
}

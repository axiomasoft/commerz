<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum NegotiatedQuoteStatus: string
{
    case Sent = 'sent';
    case RenegotiationRequested = 'renegotiation_requested';
    case Converted = 'converted';
    case Expired = 'expired';
    case Rejected = 'rejected';
    case Superseded = 'superseded';
}

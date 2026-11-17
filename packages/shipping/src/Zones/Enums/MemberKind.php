<?php

declare(strict_types=1);

namespace Commerz\Shipping\Zones\Enums;

use Packman\Attributes\Api;

/** What a zone member matches: a whole country, one region of it, or the postal codes starting with a prefix. */
#[Api(since: '0.1')]
enum MemberKind: string
{
    case Country = 'country';
    case Region = 'region';
    case PostalPrefix = 'postal_prefix';
}

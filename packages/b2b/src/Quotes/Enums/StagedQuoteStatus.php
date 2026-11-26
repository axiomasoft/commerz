<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum StagedQuoteStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
    case Abandoned = 'abandoned';
}

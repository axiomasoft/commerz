<?php

declare(strict_types=1);

namespace Commerz\Catalog\Listings\Enums;

use Commerz\Attributes\Api;

/** Whether the product is offered in the channel (within the listing's availability window). */
#[Api(since: '0.1')]
enum ListingStatus: string
{
    case Published = 'published';
    case Unpublished = 'unpublished';
}

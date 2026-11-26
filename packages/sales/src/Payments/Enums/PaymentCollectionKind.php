<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Commerz\Sales\Payments\Data\PaymentCollectionView;
use Packman\Attributes\Api;

/** Target purpose of a {@see PaymentCollectionView}. */
#[Api(since: '0.1')]
enum PaymentCollectionKind: string
{
    case Order = 'order';
    case TopUp = 'top_up';
}

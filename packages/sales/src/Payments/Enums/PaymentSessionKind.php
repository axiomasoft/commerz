<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Commerz\Sales\Payments\Data\PaymentSessionView;
use Packman\Attributes\Api;

/** Tender backend of a {@see PaymentSessionView}. */
#[Api(since: '0.1')]
enum PaymentSessionKind: string
{
    case Gateway = 'gateway';
    case StoredValue = 'stored_value';
}

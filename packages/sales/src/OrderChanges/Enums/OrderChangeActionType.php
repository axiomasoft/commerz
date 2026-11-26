<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Enums;

use Packman\Attributes\Api;

/** Supported mutation actions inside an OrderChange. */
#[Api(since: '0.1')]
enum OrderChangeActionType: string
{
    case AddLine = 'add_line';
    case ChangeLineQuantity = 'change_line_quantity';
    case RemoveLine = 'remove_line';
    case OverrideUnitPrice = 'override_unit_price';
    case ChangeShippingMethod = 'change_shipping_method';
    case ReplaceAdjustments = 'replace_adjustments';
}

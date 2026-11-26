<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Enums;

use Packman\Attributes\Api;

/**
 * Standardized RMA return / claim reason classification.
 */
#[Api(since: '0.1')]
enum ReturnReason: string
{
    case Defective = 'defective';
    case DamagedInTransit = 'damaged_in_transit';
    case WrongItem = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case ChangedMind = 'changed_mind';
    case SizeOrFit = 'size_or_fit';
    case Other = 'other';
}

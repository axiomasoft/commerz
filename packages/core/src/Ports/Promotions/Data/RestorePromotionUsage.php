<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Ports\Promotions\Enums\RestoreReason;

#[Api(since: '0.1')]
final readonly class RestorePromotionUsage
{
    public function __construct(public string $orderRef, public RestoreReason $reason)
    {
        if ($orderRef === '' || strlen($orderRef) > 191) {
            throw new InvalidCommand('orderRef', 'must be 1..191 bytes', 'promotions.invalid_usage');
        }
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Ports\Promotions\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;

#[Api(since: '0.1')]
final readonly class ConsumePromotionUsage
{
    /**
     * @param  string  $orderRef  e.g. `order:01J…`
     * @param  list<AppliedPromotion>  $applied
     */
    public function __construct(public string $orderRef, public ?CustomerRef $customer, public array $applied)
    {
        if ($orderRef === '' || strlen($orderRef) > 191) {
            throw new InvalidCommand('orderRef', 'must be 1..191 bytes', 'promotions.invalid_usage');
        }
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Pricing\Markets\Events;

use Commerz\Pricing\Markets\Data\MarketId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class MarketChanged
{
    public function __construct(public MarketId $market) {}
}

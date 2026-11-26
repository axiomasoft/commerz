<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Data;

use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class CancelOrderExchange
{
    public function __construct(
        public ExchangeId $exchange,
        public ?ActorRef $actor = null,
    ) {}
}

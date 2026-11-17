<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Context\CommerceContext;
use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

/** The context fixes the channel, currency and locale of the cart for good; its `at` is not kept. */
#[Api(since: '0.1')]
final readonly class CreateCart
{
    public function __construct(public CommerceContext $context, public ?ActorRef $actor = null) {}
}

<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Packman\Attributes\Api;

/** Increases the authorized amount on an open gateway payment session (requires gateway `incrementalAuth`). */
#[Api(since: '0.1')]
final readonly class IncrementalAuthorizeSession
{
    private const string CODE = 'sales.invalid_payment';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentSessionId $session,
        public Money $additionalAmount,
        public array $meta = [],
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        if (! $additionalAmount->isPositive()) {
            throw new InvalidCommand('additionalAmount', 'must be positive', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}

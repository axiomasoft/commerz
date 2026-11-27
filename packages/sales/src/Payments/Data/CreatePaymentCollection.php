<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Enums\PaymentCollectionKind;
use Packman\Attributes\Api;

/** Opens a payment collection for an order or top-up target. */
#[Api(since: '0.1')]
final readonly class CreatePaymentCollection
{
    private const string CODE = 'sales.invalid_payment';

    public string $targetRef;

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        string $targetRef,
        public Money $amount,
        public PaymentCollectionKind $kind = PaymentCollectionKind::Order,
        public ?OrderId $order = null,
        public ?CustomerRef $customer = null,
        public ?string $customerEmail = null,
        public array $meta = [],
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        $targetRef = trim($targetRef);
        GatewayMeta::assertReference($targetRef, 'targetRef', self::CODE);

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        if ($kind === PaymentCollectionKind::Order && $order === null) {
            throw new InvalidCommand('order', 'order collection requires an OrderId', self::CODE);
        }

        if ($customerEmail !== null && ($customerEmail === '' || strlen($customerEmail) > 191)) {
            throw new InvalidCommand('customerEmail', 'must be null or 1..191 bytes', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);

        $this->targetRef = $targetRef;
    }

    /** @param  array<string, scalar>  $meta */
    public static function forOrder(
        OrderId $order,
        Money $amount,
        ?CustomerRef $customer = null,
        ?string $customerEmail = null,
        array $meta = [],
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): self {
        return new self(
            targetRef: 'order:'.$order->value,
            amount: $amount,
            kind: PaymentCollectionKind::Order,
            order: $order,
            customer: $customer,
            customerEmail: $customerEmail,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        );
    }

    /** @param  array<string, scalar>  $meta */
    public static function forTopUp(
        string $targetRef,
        Money $amount,
        ?CustomerRef $customer = null,
        ?string $customerEmail = null,
        array $meta = [],
        ?IdempotencyKey $idempotencyKey = null,
        ?ActorRef $actor = null,
    ): self {
        return new self(
            targetRef: $targetRef,
            amount: $amount,
            kind: PaymentCollectionKind::TopUp,
            customer: $customer,
            customerEmail: $customerEmail,
            meta: $meta,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        );
    }
}

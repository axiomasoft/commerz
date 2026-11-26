<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Events;

use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentCollectionView;
use Commerz\Sales\Payments\Enums\PaymentCollectionStatus;
use Packman\Attributes\Api;

/** Dispatched after commit whenever a {@see PaymentCollectionView}'s derived status changes. */
#[Api(since: '0.1')]
final readonly class PaymentCollectionStatusChanged
{
    public function __construct(
        public PaymentCollectionId $collection,
        public string $targetRef,
        public ?OrderId $order,
        public PaymentCollectionStatus $from,
        public PaymentCollectionStatus $to,
    ) {}
}

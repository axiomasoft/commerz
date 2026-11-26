<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

/** Cancels a payment collection that has no captured funds, voiding/releasing all open sessions. */
#[Api(since: '0.1')]
final readonly class CancelPaymentCollection
{
    private const string CODE = 'sales.invalid_payment';

    public ?string $reason;

    public function __construct(
        public PaymentCollectionId $collection,
        ?string $reason = null,
        public ?ActorRef $actor = null,
    ) {
        $reason = $reason !== null ? trim($reason) : null;

        if ($reason !== null && ($reason === '' || strlen($reason) > 191)) {
            throw new InvalidCommand('reason', 'must be null or 1..191 bytes', self::CODE);
        }

        $this->reason = $reason;
    }
}

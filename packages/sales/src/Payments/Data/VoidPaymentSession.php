<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Packman\Attributes\Api;

/** Voids an uncaptured gateway authorization or releases a stored-value hold on a payment session. */
#[Api(since: '0.1')]
final readonly class VoidPaymentSession
{
    private const string CODE = 'sales.invalid_payment';

    public ?string $reason;

    public function __construct(
        public PaymentSessionId $session,
        ?string $reason = null,
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        $reason = $reason !== null ? trim($reason) : null;

        if ($reason !== null && ($reason === '' || strlen($reason) > 191)) {
            throw new InvalidCommand('reason', 'must be null or 1..191 bytes', self::CODE);
        }

        $this->reason = $reason;
    }
}

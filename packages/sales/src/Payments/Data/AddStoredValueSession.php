<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\IdempotencyKey;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Adds a stored-value tender session (gift card or store-credit wallet) to a payment collection via
 * {@see StoredValueTender}.
 */
#[Api(since: '0.1')]
final readonly class AddStoredValueSession
{
    private const string CODE = 'sales.invalid_payment';

    public string $accountId;

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentCollectionId $collection,
        public StoredValueKind $storedValueKind,
        string $accountId,
        public Money $amount,
        public bool $captureImmediately = false,
        public ?DateTimeImmutable $expiresAt = null,
        public array $meta = [],
        public ?IdempotencyKey $idempotencyKey = null,
        public ?ActorRef $actor = null,
    ) {
        $accountId = trim($accountId);

        if ($accountId === '' || strlen($accountId) > 191) {
            throw new InvalidCommand('accountId', 'must be 1..191 bytes', self::CODE);
        }

        if (! $amount->isPositive()) {
            throw new InvalidCommand('amount', 'must be positive', self::CODE);
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);

        $this->accountId = $accountId;
    }
}

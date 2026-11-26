<?php

declare(strict_types=1);

namespace Commerz\StoredValue\GiftCards\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/** Command to issue a new prepaid gift card with an opening double-entry `issue` journal. */
#[Api(since: '0.1')]
final readonly class IssueGiftCard
{
    public ?DateTimeImmutable $expiresAt;

    public ?string $customCode;

    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public Money $initialBalance,
        public ?CustomerRef $recipient = null,
        ?DateTimeImmutable $expiresAt = null,
        ?string $customCode = null,
        public ?string $reference = null,
        public ?string $idempotencyKey = null,
        public ?string $actorRef = null,
        public array $metadata = [],
    ) {
        if (! $initialBalance->isPositive()) {
            throw new InvalidCommand('initialBalance', 'gift card initial balance must be positive', 'stored_value.invalid_amount');
        }

        if ($customCode !== null) {
            GiftCardCode::normalize($customCode);
        }

        $this->customCode = $customCode !== null ? trim($customCode) : null;
        $this->expiresAt = $expiresAt?->setTimezone(new DateTimeZone('UTC'));
    }
}

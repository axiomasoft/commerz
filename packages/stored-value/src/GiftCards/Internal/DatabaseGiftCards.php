<?php

declare(strict_types=1);

namespace Commerz\StoredValue\GiftCards\Internal;

use Commerz\Errors\InvalidCommand;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\StoredValue\Configuration\StoredValueOptions;
use Commerz\StoredValue\GiftCards\Contracts\GiftCards;
use Commerz\StoredValue\GiftCards\Data\GiftCardCode;
use Commerz\StoredValue\GiftCards\Data\GiftCardView;
use Commerz\StoredValue\GiftCards\Data\IssuedGiftCard;
use Commerz\StoredValue\GiftCards\Data\IssueGiftCard;
use Commerz\StoredValue\Ledger\Data\AccountId;
use Commerz\StoredValue\Ledger\Enums\AccountStatus;
use Commerz\StoredValue\Ledger\Internal\DoubleEntryLedger;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseGiftCards implements GiftCards
{
    private const int MAX_CODE_GENERATION_ATTEMPTS = 5;

    public function __construct(
        private DoubleEntryLedger $ledger,
        private StoredValueOptions $options,
        private Clock $clock,
    ) {}

    #[Override]
    public function issue(IssueGiftCard $command): IssuedGiftCard
    {
        $expiresAt = $command->expiresAt;

        if ($expiresAt === null && $this->options->defaultExpiryDays !== null) {
            $expiresAt = PgTimestamp::current($this->clock)
                ->add(new DateInterval('P'.$this->options->defaultExpiryDays.'D'));
        }

        if ($command->customCode !== null) {
            return $this->issueWithCode($command, $command->customCode, $expiresAt);
        }

        for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
            $plainCode = GiftCardCode::generate(
                length: $this->options->codeLength,
                alphabet: $this->options->codeAlphabet,
                prefix: $this->options->codePrefix,
            );

            try {
                return $this->issueWithCode($command, $plainCode, $expiresAt);
            } catch (UniqueConstraintViolationException) {
                // Retry with a fresh random code on collision.
            }
        }

        throw new InvalidCommand('code', 'unable to generate a unique gift card code', 'stored_value.duplicate_code');
    }

    #[Override]
    public function find(AccountId $id): ?GiftCardView
    {
        $snapshot = $this->ledger->findAccount($id);

        if ($snapshot === null || $snapshot->kind !== StoredValueKind::GiftCard) {
            return null;
        }

        return GiftCardView::fromSnapshot($snapshot);
    }

    #[Override]
    public function findByCode(string $plainCode): ?GiftCardView
    {
        try {
            $hash = GiftCardCode::hash($plainCode);
        } catch (InvalidCommand) {
            return null;
        }

        $snapshot = $this->ledger->findAccountByCodeHash($hash);

        return $snapshot === null ? null : GiftCardView::fromSnapshot($snapshot);
    }

    #[Override]
    public function freeze(AccountId $id, ?string $reason = null): GiftCardView
    {
        return GiftCardView::fromSnapshot($this->ledger->transitionStatus($id, AccountStatus::Frozen, $reason));
    }

    #[Override]
    public function unfreeze(AccountId $id): GiftCardView
    {
        return GiftCardView::fromSnapshot($this->ledger->transitionStatus($id, AccountStatus::Active));
    }

    #[Override]
    public function void(AccountId $id, ?string $reason = null): GiftCardView
    {
        return GiftCardView::fromSnapshot($this->ledger->transitionStatus($id, AccountStatus::Voided, $reason));
    }

    #[Override]
    public function expireDue(?DateTimeImmutable $asOf = null, ?string $actorRef = null): int
    {
        return $this->ledger->expireDueAccounts($asOf, $actorRef);
    }

    private function issueWithCode(IssueGiftCard $command, string $plainCode, ?DateTimeImmutable $expiresAt): IssuedGiftCard
    {
        $snapshot = $this->ledger->createAccount(
            kind: StoredValueKind::GiftCard,
            initialBalance: $command->initialBalance,
            codeHash: GiftCardCode::hash($plainCode),
            codeLast4: GiftCardCode::last4($plainCode),
            customer: $command->recipient,
            expiresAt: $expiresAt,
            metadata: $command->metadata,
            reference: $command->reference,
            idempotencyKey: $command->idempotencyKey,
            actorRef: $command->actorRef,
            reason: 'gift_card_issued',
        );

        return new IssuedGiftCard(
            giftCard: GiftCardView::fromSnapshot($snapshot),
            plainCode: $plainCode,
        );
    }
}

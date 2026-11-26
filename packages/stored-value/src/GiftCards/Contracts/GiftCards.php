<?php

declare(strict_types=1);

namespace Commerz\StoredValue\GiftCards\Contracts;

use Commerz\StoredValue\GiftCards\Data\GiftCardView;
use Commerz\StoredValue\GiftCards\Data\IssuedGiftCard;
use Commerz\StoredValue\GiftCards\Data\IssueGiftCard;
use Commerz\StoredValue\Ledger\Data\AccountId;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Issuance, lookup (by SHA-256 hash), lifecycle management, and expiry of prepaid gift cards. */
#[Api(since: '0.1')]
interface GiftCards
{
    public function issue(IssueGiftCard $command): IssuedGiftCard;

    public function find(AccountId $id): ?GiftCardView;

    public function findByCode(string $plainCode): ?GiftCardView;

    public function freeze(AccountId $id, ?string $reason = null): GiftCardView;

    public function unfreeze(AccountId $id): GiftCardView;

    public function void(AccountId $id, ?string $reason = null): GiftCardView;

    public function expireDue(?DateTimeImmutable $asOf = null, ?string $actorRef = null): int;
}

<?php

declare(strict_types=1);

namespace Commerz\Ports\StoredValue\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Packman\Attributes\Api;

/**
 * A stored-value instrument presented as payment tender after taxes.
 */
#[Api(since: '0.1')]
final readonly class TenderInstrument
{
    public ?string $accountId;

    public ?string $giftCardCode;

    public function __construct(
        public StoredValueKind $kind,
        ?string $accountId = null,
        ?string $giftCardCode = null,
        public ?CustomerRef $customer = null,
        public ?Money $maxAmount = null,
    ) {
        $accountId = $accountId !== null ? trim($accountId) : null;
        $giftCardCode = $giftCardCode !== null ? trim($giftCardCode) : null;

        if ($accountId === '') {
            throw new InvalidCommand('accountId', 'must be non-empty when provided', 'stored_value.invalid_account');
        }

        if ($giftCardCode === '') {
            throw new InvalidCommand('giftCardCode', 'must be non-empty when provided', 'stored_value.invalid_code');
        }

        if ($kind === StoredValueKind::GiftCard && $accountId === null && $giftCardCode === null) {
            throw new InvalidCommand('giftCardCode', 'a gift card tender needs either accountId or giftCardCode', 'stored_value.invalid_tender');
        }

        if ($kind === StoredValueKind::Wallet && $accountId === null && $customer === null) {
            throw new InvalidCommand('customer', 'a wallet tender needs either accountId or customer', 'stored_value.invalid_tender');
        }

        if ($maxAmount !== null && ! $maxAmount->isPositive()) {
            throw new InvalidCommand('maxAmount', 'must be positive when provided', 'stored_value.invalid_amount');
        }

        $this->accountId = $accountId;
        $this->giftCardCode = $giftCardCode;
    }

    public static function giftCardCode(string $code, ?Money $maxAmount = null): self
    {
        return new self(
            kind: StoredValueKind::GiftCard,
            giftCardCode: $code,
            maxAmount: $maxAmount,
        );
    }

    public static function giftCardAccount(string $accountId, ?Money $maxAmount = null): self
    {
        return new self(
            kind: StoredValueKind::GiftCard,
            accountId: $accountId,
            maxAmount: $maxAmount,
        );
    }

    public static function walletFor(CustomerRef $customer, ?Money $maxAmount = null): self
    {
        return new self(
            kind: StoredValueKind::Wallet,
            customer: $customer,
            maxAmount: $maxAmount,
        );
    }

    public static function walletAccount(string $accountId, ?Money $maxAmount = null): self
    {
        return new self(
            kind: StoredValueKind::Wallet,
            accountId: $accountId,
            maxAmount: $maxAmount,
        );
    }
}

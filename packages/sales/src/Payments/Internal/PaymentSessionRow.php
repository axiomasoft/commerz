<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\StoredValue\Enums\StoredValueKind;
use Commerz\Sales\Payments\Data\PaymentCaptureView;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentSessionId;
use Commerz\Sales\Payments\Data\PaymentSessionView;
use Commerz\Sales\Payments\Enums\PaymentSessionKind;
use Commerz\Sales\Payments\Enums\PaymentSessionStatus;
use Commerz\Schema\Rows;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final readonly class PaymentSessionRow
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentSessionId $id,
        public PaymentCollectionId $collection,
        public PaymentSessionKind $kind,
        public ?string $gateway,
        public ?StoredValueKind $storedValueKind,
        public ?string $storedValueAccountId,
        public ?string $storedValueHoldId,
        public string $reference,
        public ?string $externalId,
        public PaymentSessionStatus $status,
        public Currency $currency,
        public int $amount,
        public int $authorized = 0,
        public int $captured = 0,
        public bool $captureClosed = false,
        public ?string $actionUrl = null,
        public array $meta = [],
        public int $attempts = 1,
        public string $claimedAt = '',
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}

    public static function fromRow(object $row): self
    {
        $meta = [];

        foreach (Rows::json($row, 'meta') as $key => $value) {
            $meta[(string) $key] = is_scalar($value) ? $value : throw new RuntimeException("Session meta '{$key}' is not a scalar.");
        }

        $svKind = Rows::nullableString($row, 'stored_value_kind');

        return new self(
            new PaymentSessionId(Rows::string($row, 'id')),
            new PaymentCollectionId(Rows::string($row, 'collection_id')),
            PaymentSessionKind::from(Rows::string($row, 'kind')),
            Rows::nullableString($row, 'gateway'),
            $svKind === null ? null : StoredValueKind::from($svKind),
            Rows::nullableString($row, 'stored_value_account_id'),
            Rows::nullableString($row, 'stored_value_hold_id'),
            Rows::string($row, 'reference'),
            Rows::nullableString($row, 'external_id'),
            PaymentSessionStatus::from(Rows::string($row, 'status')),
            Currency::of(Rows::string($row, 'currency')),
            Rows::int($row, 'amount_minor'),
            Rows::int($row, 'authorized_minor'),
            Rows::int($row, 'captured_minor'),
            self::readBool($row, 'capture_closed'),
            Rows::nullableString($row, 'action_url'),
            $meta,
            Rows::int($row, 'attempts'),
            Rows::string($row, 'claimed_at'),
            Rows::nullableString($row, 'error_code'),
            Rows::nullableString($row, 'error_message'),
            Rows::string($row, 'created_at'),
            Rows::string($row, 'updated_at'),
        );
    }

    /** @param  list<PaymentCaptureView>  $captures */
    public function view(array $captures): PaymentSessionView
    {
        return new PaymentSessionView(
            $this->id,
            $this->collection,
            $this->kind,
            $this->gateway,
            $this->storedValueKind,
            $this->storedValueAccountId,
            $this->storedValueHoldId,
            $this->reference,
            $this->externalId,
            $this->status,
            Money::ofMinor($this->amount, $this->currency),
            Money::ofMinor($this->authorized, $this->currency),
            Money::ofMinor($this->captured, $this->currency),
            $this->captureClosed,
            $this->actionUrl,
            $this->meta,
            $this->attempts,
            $this->errorCode,
            $this->errorMessage,
            $captures,
            new DateTimeImmutable($this->createdAt),
            new DateTimeImmutable($this->updatedAt),
        );
    }

    private static function readBool(object $row, string $column): bool
    {
        $vars = get_object_vars($row);
        $value = $vars[$column] ?? null;

        return match (true) {
            is_bool($value) => $value,
            $value === 0, $value === '0' => false,
            $value === 1, $value === '1' => true,
            default => throw new RuntimeException("Column '{$column}' is missing or not a boolean."),
        };
    }
}

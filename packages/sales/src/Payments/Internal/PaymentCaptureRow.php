<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sales\Payments\Data\PaymentCaptureId;
use Commerz\Sales\Payments\Data\PaymentCaptureView;
use Commerz\Sales\Payments\Data\PaymentCollectionId;
use Commerz\Sales\Payments\Data\PaymentSessionId;
use Commerz\Sales\Payments\Enums\PaymentCaptureStatus;
use Commerz\Schema\Rows;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final readonly class PaymentCaptureRow
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentCaptureId $id,
        public PaymentSessionId $session,
        public PaymentCollectionId $collection,
        public string $reference,
        public ?string $externalId,
        public PaymentCaptureStatus $status,
        public Currency $currency,
        public int $amount,
        public bool $isFinalCapture,
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
            $meta[(string) $key] = is_scalar($value) ? $value : throw new RuntimeException("Capture meta '{$key}' is not a scalar.");
        }

        return new self(
            new PaymentCaptureId(Rows::string($row, 'id')),
            new PaymentSessionId(Rows::string($row, 'session_id')),
            new PaymentCollectionId(Rows::string($row, 'collection_id')),
            Rows::string($row, 'reference'),
            Rows::nullableString($row, 'external_id'),
            PaymentCaptureStatus::from(Rows::string($row, 'status')),
            Currency::of(Rows::string($row, 'currency')),
            Rows::int($row, 'amount_minor'),
            self::readBool($row, 'is_final_capture'),
            $meta,
            Rows::int($row, 'attempts'),
            Rows::string($row, 'claimed_at'),
            Rows::nullableString($row, 'error_code'),
            Rows::nullableString($row, 'error_message'),
            Rows::string($row, 'created_at'),
            Rows::string($row, 'updated_at'),
        );
    }

    public function view(): PaymentCaptureView
    {
        return new PaymentCaptureView(
            $this->id,
            $this->session,
            $this->collection,
            $this->reference,
            $this->externalId,
            $this->status,
            Money::ofMinor($this->amount, $this->currency),
            $this->isFinalCapture,
            $this->meta,
            $this->errorCode,
            $this->errorMessage,
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

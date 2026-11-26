<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Money\Money;
use Commerz\Sales\Payments\Enums\PaymentCaptureStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** One capture attempt recorded against a {@see PaymentSessionView} (supports multicapture). */
#[Api(since: '0.1')]
final readonly class PaymentCaptureView
{
    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public PaymentCaptureId $id,
        public PaymentSessionId $session,
        public PaymentCollectionId $collection,
        public string $reference,
        public ?string $externalId,
        public PaymentCaptureStatus $status,
        public Money $amount,
        public bool $isFinalCapture,
        public array $meta,
        public ?string $errorCode,
        public ?string $errorMessage,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}

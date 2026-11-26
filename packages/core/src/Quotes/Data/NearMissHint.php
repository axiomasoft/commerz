<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

/**
 * A storefront upsell hint explaining how close the quote is to unlocking a promotion (e.g. missing spend or units).
 */
#[Api(since: '0.1')]
final readonly class NearMissHint
{
    public function __construct(
        public string $promotionId,
        public string $promotionCode,
        public string $promotionName,
        public string $reason,
        public string $message,
        public ?Money $missingAmount = null,
        public ?int $missingQuantity = null,
        public ?Money $thresholdAmount = null,
        public ?int $thresholdQuantity = null,
    ) {
        if (trim($promotionId) === '') {
            throw new InvalidCommand('promotionId', 'must not be empty', 'quotes.invalid_near_miss_hint');
        }

        if (trim($promotionCode) === '') {
            throw new InvalidCommand('promotionCode', 'must not be empty', 'quotes.invalid_near_miss_hint');
        }

        if (trim($promotionName) === '') {
            throw new InvalidCommand('promotionName', 'must not be empty', 'quotes.invalid_near_miss_hint');
        }

        if (trim($reason) === '') {
            throw new InvalidCommand('reason', 'must not be empty', 'quotes.invalid_near_miss_hint');
        }

        if (trim($message) === '') {
            throw new InvalidCommand('message', 'must not be empty', 'quotes.invalid_near_miss_hint');
        }

        if ($missingAmount instanceof Money && ! $missingAmount->isPositive()) {
            throw new InvalidCommand('missingAmount', 'must be greater than zero', 'quotes.invalid_near_miss_hint');
        }

        if ($missingQuantity !== null && $missingQuantity < 1) {
            throw new InvalidCommand('missingQuantity', 'must be at least 1', 'quotes.invalid_near_miss_hint');
        }

        if ($thresholdAmount instanceof Money && ! $thresholdAmount->isPositive()) {
            throw new InvalidCommand('thresholdAmount', 'must be greater than zero', 'quotes.invalid_near_miss_hint');
        }

        if ($thresholdQuantity !== null && $thresholdQuantity < 1) {
            throw new InvalidCommand('thresholdQuantity', 'must be at least 1', 'quotes.invalid_near_miss_hint');
        }

        if ($missingAmount instanceof Money && $thresholdAmount instanceof Money && ! $missingAmount->currency->equals($thresholdAmount->currency)) {
            throw new InvalidCommand('missingAmount', 'currency must match thresholdAmount', 'quotes.currency_mismatch');
        }
    }
}

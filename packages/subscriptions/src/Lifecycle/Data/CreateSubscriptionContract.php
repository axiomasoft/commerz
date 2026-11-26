<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Lifecycle\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Sellable\BillingPolicy;
use Commerz\Sellable\SellingPlan;
use Commerz\Stores\Data\ChannelId;
use Commerz\Subscriptions\Billing\Data\BillingCycle;
use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Command to establish a recurring {@see SubscriptionContract} and schedule its next {@see BillingCycle}.
 */
#[Api(since: '0.1')]
final readonly class CreateSubscriptionContract
{
    /**
     * @param  list<SubscriptionLineInput>  $lines
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public ChannelId $channelId,
        public Currency $currency,
        public CustomerRef $customer,
        public SellingPlan $sellingPlan,
        public array $lines,
        public ?string $paymentMethodRef = null,
        public ?string $originOrderRef = null,
        public ?string $originLineId = null,
        public string $locale = 'en',
        public ?RetryPolicy $retryPolicy = null,
        public ?DateTimeImmutable $startedAt = null,
        public ?string $idempotencyKey = null,
        public array $metadata = [],
        public ?Money $initialBilledAmount = null,
    ) {
        if ($sellingPlan->billingPolicy !== BillingPolicy::Recurring) {
            throw new InvalidCommand(
                'sellingPlan',
                'SubscriptionContract requires a SellingPlan with BillingPolicy::Recurring',
                'subscriptions.invalid_selling_plan',
            );
        }

        if ($lines === []) {
            throw new InvalidCommand('lines', 'must include at least one subscription line', 'subscriptions.invalid_command');
        }

        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->lineId])) {
                throw new InvalidCommand('lines', "duplicate subscription lineId '{$line->lineId}'", 'subscriptions.invalid_command');
            }
            $seen[$line->lineId] = true;

            $candidatePrice = $line->unitPriceOverride ?? $line->sellable->basePrice ?? $line->sellable->unitPrice?->amount;
            if ($candidatePrice !== null && ! $candidatePrice->currency->equals($currency)) {
                throw new InvalidCommand(
                    'currency',
                    "line '{$line->lineId}' price currency {$candidatePrice->currency->code} does not match {$currency->code}",
                    'subscriptions.currency_mismatch',
                );
            }
        }

        if ($sellingPlan->requiresSavedPaymentMethod && ($paymentMethodRef === null || trim($paymentMethodRef) === '')) {
            throw new InvalidCommand(
                'paymentMethodRef',
                'a saved paymentMethodRef is required for recurring selling plans',
                'subscriptions.missing_payment_method',
            );
        }

        if ($paymentMethodRef !== null && strlen($paymentMethodRef) > 191) {
            throw new InvalidCommand('paymentMethodRef', 'must fit within 191 bytes', 'subscriptions.invalid_command');
        }

        if ($originOrderRef !== null && (trim($originOrderRef) === '' || strlen($originOrderRef) > 191)) {
            throw new InvalidCommand('originOrderRef', 'must be 1..191 non-blank bytes', 'subscriptions.invalid_command');
        }

        if ($originLineId !== null && (trim($originLineId) === '' || strlen($originLineId) > 64)) {
            throw new InvalidCommand('originLineId', 'must be 1..64 non-blank bytes', 'subscriptions.invalid_command');
        }

        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 191)) {
            throw new InvalidCommand('idempotencyKey', 'must be 1..191 non-blank bytes', 'subscriptions.invalid_command');
        }

        if (trim($locale) === '' || strlen($locale) > 16) {
            throw new InvalidCommand('locale', 'must be 1..16 non-blank bytes', 'subscriptions.invalid_command');
        }

        if ($initialBilledAmount !== null) {
            if (! $initialBilledAmount->currency->equals($currency)) {
                throw new InvalidCommand('initialBilledAmount', 'currency must match contract currency', 'subscriptions.currency_mismatch');
            }
            if ($initialBilledAmount->isNegative()) {
                throw new InvalidCommand('initialBilledAmount', 'must not be negative', 'subscriptions.invalid_command');
            }
        }
    }
}

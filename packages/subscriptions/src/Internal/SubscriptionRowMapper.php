<?php

declare(strict_types=1);

namespace Commerz\Subscriptions\Internal;

use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Measures\QuantityStepPolicy;
use Commerz\Measures\UnitPrice;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sellable\SellingPlan;
use Commerz\Stores\Data\ChannelId;
use Commerz\Subscriptions\Billing\Data\BillingCycle;
use Commerz\Subscriptions\Billing\Data\BillingCycleId;
use Commerz\Subscriptions\Billing\Data\RetryPolicy;
use Commerz\Subscriptions\Billing\Enums\BillingCycleStatus;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContract;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionContractId;
use Commerz\Subscriptions\Lifecycle\Data\SubscriptionLine;
use Commerz\Subscriptions\Lifecycle\Enums\SubscriptionStatus;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderId;
use Commerz\Subscriptions\Renewals\Data\RenewalOrderView;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

/**
 * Strict database row and JSON codec helper for `axiomasoft/commerz-subscriptions`.
 */
#[Internal]
final class SubscriptionRowMapper
{
    /**
     * @param  list<SubscriptionLine>  $lines
     */
    public static function contract(object $row, array $lines): SubscriptionContract
    {
        $currency = Currency::of(self::string($row, 'currency'));
        $sellingPlanData = self::jsonObject($row, 'selling_plan');
        $retryPolicyData = self::jsonObject($row, 'retry_policy');
        $metadata = self::jsonObject($row, 'metadata');

        return new SubscriptionContract(
            id: new SubscriptionContractId(self::string($row, 'id')),
            channelId: new ChannelId(self::string($row, 'channel_id')),
            currency: $currency,
            locale: self::string($row, 'locale', 'en'),
            customer: CustomerRef::fromString(self::string($row, 'customer_ref')),
            status: SubscriptionStatus::from(self::string($row, 'status')),
            sellingPlan: SellingPlan::fromArray($sellingPlanData),
            retryPolicy: RetryPolicy::fromArray($retryPolicyData),
            paymentMethodRef: self::nullableString($row, 'payment_method_ref'),
            originOrderRef: self::nullableString($row, 'origin_order_ref'),
            originLineId: self::nullableString($row, 'origin_line_id'),
            lines: $lines,
            completedCycles: self::int($row, 'completed_cycles'),
            consecutiveFailures: self::int($row, 'consecutive_failures'),
            nextBillingAt: self::nullableInstant($row, 'next_billing_at'),
            pausedAt: self::nullableInstant($row, 'paused_at'),
            canceledAt: self::nullableInstant($row, 'canceled_at'),
            cancelReason: self::nullableString($row, 'cancel_reason'),
            version: self::int($row, 'version'),
            metadata: $metadata,
            createdAt: self::instant($row, 'created_at'),
            updatedAt: self::instant($row, 'updated_at'),
        );
    }

    public static function line(object $row, Currency $currency): SubscriptionLine
    {
        $baseUnitPrice = Money::ofMinor(self::int($row, 'base_unit_price_minor'), $currency);
        $snapshotData = self::jsonObject($row, 'snapshot');

        return new SubscriptionLine(
            lineId: self::string($row, 'line_id'),
            sellable: self::hydrateSnapshot($row, $snapshotData, $baseUnitPrice),
            quantity: self::int($row, 'quantity'),
            baseUnitPrice: $baseUnitPrice,
        );
    }

    public static function cycle(object $row): BillingCycle
    {
        $currency = Currency::of(self::string($row, 'currency'));
        $billedMinor = self::nullableInt($row, 'billed_amount_minor');

        return new BillingCycle(
            id: new BillingCycleId(self::string($row, 'id')),
            contractId: new SubscriptionContractId(self::string($row, 'contract_id')),
            cycleNumber: self::int($row, 'cycle_number'),
            status: BillingCycleStatus::from(self::string($row, 'status')),
            scheduledAt: self::instant($row, 'scheduled_at'),
            nextAttemptAt: self::nullableInstant($row, 'next_attempt_at'),
            attempts: self::int($row, 'attempts'),
            currency: $currency,
            billedAmount: $billedMinor !== null ? Money::ofMinor($billedMinor, $currency) : null,
            orderRef: self::nullableString($row, 'order_ref'),
            lastError: self::nullableString($row, 'last_error'),
            billedAt: self::nullableInstant($row, 'billed_at'),
            createdAt: self::instant($row, 'created_at'),
            updatedAt: self::instant($row, 'updated_at'),
        );
    }

    public static function renewalOrder(object $row): RenewalOrderView
    {
        $currency = Currency::of(self::string($row, 'currency'));
        $rawLines = self::jsonArray($row, 'lines_snapshot');
        $lines = [];
        foreach ($rawLines as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $typed */
                $typed = $entry;
                $lines[] = $typed;
            }
        }

        return new RenewalOrderView(
            id: new RenewalOrderId(self::string($row, 'id')),
            orderRef: self::string($row, 'order_ref'),
            contractId: new SubscriptionContractId(self::string($row, 'contract_id')),
            cycleId: new BillingCycleId(self::string($row, 'cycle_id')),
            cycleNumber: self::int($row, 'cycle_number'),
            channelId: new ChannelId(self::string($row, 'channel_id')),
            customer: CustomerRef::fromString(self::string($row, 'customer_ref')),
            paymentMethodRef: self::nullableString($row, 'payment_method_ref'),
            subtotal: Money::ofMinor(self::int($row, 'subtotal_minor'), $currency),
            discountTotal: Money::ofMinor(self::int($row, 'discount_total_minor'), $currency),
            deliveryTotal: Money::ofMinor(self::int($row, 'delivery_total_minor'), $currency),
            taxTotal: Money::ofMinor(self::int($row, 'tax_total_minor'), $currency),
            total: Money::ofMinor(self::int($row, 'total_minor'), $currency),
            dueNowAmount: Money::ofMinor(self::int($row, 'due_now_minor'), $currency),
            idempotencyKey: self::string($row, 'idempotency_key'),
            linesSnapshot: $lines,
            createdAt: self::instant($row, 'created_at'),
        );
    }

    /** @return array<string, mixed> */
    public static function serializeSnapshot(SellableSnapshot $snapshot): array
    {
        return [
            'options' => $snapshot->options,
            'attributes' => $snapshot->attributes,
            'categories' => $snapshot->categories,
            'quantity_step' => $snapshot->quantityStepPolicy !== null ? [
                'min' => $snapshot->quantityStepPolicy->min,
                'step' => $snapshot->quantityStepPolicy->step,
                'max' => $snapshot->quantityStepPolicy->max,
                'unit' => $snapshot->quantityStepPolicy->unit,
            ] : null,
            'unit_price' => $snapshot->unitPrice !== null ? [
                'amount_minor' => $snapshot->unitPrice->amount->amountMinor,
                'currency' => $snapshot->unitPrice->amount->currency->code,
                'per_units' => $snapshot->unitPrice->perUnits,
                'unit' => $snapshot->unitPrice->unit,
            ] : null,
            'hs_code' => $snapshot->hsCode,
            'origin_country_code' => $snapshot->originCountryCode,
            'material' => $snapshot->material,
            'customs_description' => $snapshot->customsDescription,
            'seller_ref' => $snapshot->sellerRef !== null ? (string) $snapshot->sellerRef : null,
        ];
    }

    /** @param array<string, mixed> $snapshotData */
    private static function hydrateSnapshot(object $row, array $snapshotData, Money $baseUnitPrice): SellableSnapshot
    {
        $options = [];
        if (isset($snapshotData['options']) && is_array($snapshotData['options'])) {
            foreach ($snapshotData['options'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $options[$k] = $v;
                }
            }
        }

        $attributes = [];
        if (isset($snapshotData['attributes']) && is_array($snapshotData['attributes'])) {
            foreach ($snapshotData['attributes'] as $k => $v) {
                if (is_string($k) && is_scalar($v)) {
                    $attributes[$k] = $v;
                }
            }
        }

        $categories = [];
        if (isset($snapshotData['categories']) && is_array($snapshotData['categories'])) {
            foreach ($snapshotData['categories'] as $cat) {
                if (is_string($cat)) {
                    $categories[] = $cat;
                }
            }
        }

        $stepPolicy = null;
        if (isset($snapshotData['quantity_step']) && is_array($snapshotData['quantity_step'])) {
            $qs = $snapshotData['quantity_step'];
            if (isset($qs['min'], $qs['step'], $qs['unit']) && is_int($qs['min']) && is_int($qs['step']) && is_string($qs['unit'])) {
                $max = isset($qs['max']) && is_int($qs['max']) ? $qs['max'] : null;
                $stepPolicy = new QuantityStepPolicy($qs['min'], $qs['step'], $max, $qs['unit']);
            }
        }

        $unitPrice = null;
        if (isset($snapshotData['unit_price']) && is_array($snapshotData['unit_price'])) {
            $up = $snapshotData['unit_price'];
            if (isset($up['amount_minor'], $up['currency'], $up['per_units'], $up['unit'])
                && is_int($up['amount_minor'])
                && is_string($up['currency'])
                && is_int($up['per_units'])
                && is_string($up['unit'])) {
                $unitPrice = new UnitPrice(
                    Money::ofMinor($baseUnitPrice->amountMinor, $up['currency']),
                    $up['per_units'],
                    $up['unit'],
                );
            }
        }

        $sellerRef = isset($snapshotData['seller_ref']) && is_string($snapshotData['seller_ref'])
            ? SellerRef::fromString($snapshotData['seller_ref'])
            : null;

        return new SellableSnapshot(
            ref: SellableRef::fromString(self::string($row, 'sellable_ref')),
            sku: self::string($row, 'sku'),
            name: self::string($row, 'name'),
            kind: SellableKind::from(self::string($row, 'kind')),
            requiresShipping: self::bool($row, 'requires_shipping'),
            purchasable: true,
            options: $options,
            basePrice: $baseUnitPrice,
            taxClass: self::nullableString($row, 'tax_class'),
            weightGrams: self::nullableInt($row, 'weight_grams'),
            attributes: $attributes,
            categories: $categories,
            quantityStepPolicy: $stepPolicy,
            unitPrice: $unitPrice,
            hsCode: isset($snapshotData['hs_code']) && is_string($snapshotData['hs_code']) ? $snapshotData['hs_code'] : null,
            originCountryCode: isset($snapshotData['origin_country_code']) && is_string($snapshotData['origin_country_code']) ? $snapshotData['origin_country_code'] : null,
            material: isset($snapshotData['material']) && is_string($snapshotData['material']) ? $snapshotData['material'] : null,
            customsDescription: isset($snapshotData['customs_description']) && is_string($snapshotData['customs_description']) ? $snapshotData['customs_description'] : null,
            sellerRef: $sellerRef,
        );
    }

    public static function string(object $row, string $column, string $default = ''): string
    {
        $vars = get_object_vars($row);
        $val = $vars[$column] ?? null;

        return is_scalar($val) ? (string) $val : $default;
    }

    public static function nullableString(object $row, string $column): ?string
    {
        $vars = get_object_vars($row);
        $val = $vars[$column] ?? null;

        if ($val === null || ! is_scalar($val)) {
            return null;
        }

        $str = (string) $val;

        return $str === '' ? null : $str;
    }

    public static function int(object $row, string $column): int
    {
        $vars = get_object_vars($row);
        $val = $vars[$column] ?? 0;

        return is_numeric($val) ? (int) $val : 0;
    }

    public static function nullableInt(object $row, string $column): ?int
    {
        $vars = get_object_vars($row);
        $val = $vars[$column] ?? null;

        return $val !== null && is_numeric($val) ? (int) $val : null;
    }

    public static function bool(object $row, string $column): bool
    {
        $vars = get_object_vars($row);
        $val = $vars[$column] ?? false;

        if (is_bool($val)) {
            return $val;
        }

        if (is_int($val)) {
            return $val !== 0;
        }

        if (is_string($val)) {
            return in_array(strtolower($val), ['1', 'true', 't', 'yes'], true);
        }

        return false;
    }

    public static function instant(object $row, string $column): DateTimeImmutable
    {
        return new DateTimeImmutable(self::string($row, $column))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function nullableInstant(object $row, string $column): ?DateTimeImmutable
    {
        $raw = self::nullableString($row, $column);

        return $raw !== null ? new DateTimeImmutable($raw)->setTimezone(new DateTimeZone('UTC')) : null;
    }

    /** @return array<string, mixed> */
    public static function jsonObject(object $row, string $column): array
    {
        $raw = self::nullableString($row, $column);
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
    }

    /** @return list<mixed> */
    public static function jsonArray(object $row, string $column): array
    {
        $raw = self::nullableString($row, $column);
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}

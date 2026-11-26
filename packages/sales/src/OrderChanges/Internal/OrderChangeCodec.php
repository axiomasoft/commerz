<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Internal;

use Commerz\Errors\CommerzException;
use Commerz\Identity\SellableRef;
use Commerz\Identity\SellerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Quotes\Data\Adjustment;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Sales\OrderChanges\Data\AddLineAction;
use Commerz\Sales\OrderChanges\Data\ChangeLineQuantityAction;
use Commerz\Sales\OrderChanges\Data\ChangeShippingMethodAction;
use Commerz\Sales\OrderChanges\Data\CompensationOutcome;
use Commerz\Sales\OrderChanges\Data\OrderChangeAction;
use Commerz\Sales\OrderChanges\Data\OrderExcerpt;
use Commerz\Sales\OrderChanges\Data\OrderExcerptLine;
use Commerz\Sales\OrderChanges\Data\OverrideUnitPriceAction;
use Commerz\Sales\OrderChanges\Data\RemoveLineAction;
use Commerz\Sales\OrderChanges\Data\ReplaceAdjustmentsAction;
use Commerz\Sales\OrderChanges\Enums\CompensationStrategy;
use Commerz\Sales\OrderChanges\Enums\OrderChangeActionType;
use Commerz\Sales\Orders\Data\OrderLineId;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Refunds\Data\RefundId;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use ValueError;

/** Strict JSONB codec for `cmz_order_changes` and `cmz_order_change_actions`. */
#[Internal]
final class OrderChangeCodec
{
    /** @param  array<string, mixed>  $data */
    public static function encodeObject(array $data): string
    {
        return json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Stored order change JSON is corrupt.', previous: $e);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new LogicException('Stored order change JSON is not an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param  array<string, mixed>  $payload */
    public static function actionFromPayload(OrderChangeActionType $type, array $payload): OrderChangeAction
    {
        try {
            return match ($type) {
                OrderChangeActionType::AddLine => self::decodeAddLine($payload),
                OrderChangeActionType::ChangeLineQuantity => new ChangeLineQuantityAction(
                    new OrderLineId(self::string($payload, 'line_id')),
                    self::int($payload, 'quantity'),
                ),
                OrderChangeActionType::RemoveLine => new RemoveLineAction(
                    new OrderLineId(self::string($payload, 'line_id')),
                ),
                OrderChangeActionType::OverrideUnitPrice => self::decodeOverrideUnitPrice($payload),
                OrderChangeActionType::ChangeShippingMethod => self::decodeChangeShippingMethod($payload),
                OrderChangeActionType::ReplaceAdjustments => self::decodeReplaceAdjustments($payload),
            };
        } catch (CommerzException|ValueError $e) {
            throw new LogicException("Stored order change action '{$type->value}' is corrupt: {$e->getMessage()}", previous: $e);
        }
    }

    public static function compensationToJson(CompensationOutcome $outcome): string
    {
        return self::encodeObject([
            'strategy' => $outcome->strategy->value,
            'amount_minor' => $outcome->amount->amountMinor,
            'currency' => $outcome->amount->currency->code,
            'payment_id' => $outcome->paymentId?->value,
            'refund_id' => $outcome->refundId?->value,
            'stored_value_account_id' => $outcome->storedValueAccountId,
            'stored_value_journal_id' => $outcome->storedValueJournalId,
            'details' => (object) $outcome->details,
        ]);
    }

    public static function compensationFromRow(?string $strategy, string $json, Currency $currency): ?CompensationOutcome
    {
        if ($strategy === null) {
            return null;
        }

        $data = self::decodeObject($json);
        $paymentId = self::nullableString($data, 'payment_id');
        $refundId = self::nullableString($data, 'refund_id');
        $detailsRaw = $data['details'] ?? [];
        $details = [];

        if (is_array($detailsRaw)) {
            foreach ($detailsRaw as $key => $value) {
                if (is_string($key) && (is_scalar($value) || $value === null)) {
                    $details[$key] = $value;
                }
            }
        }

        return new CompensationOutcome(
            strategy: CompensationStrategy::from($strategy),
            amount: Money::ofMinor(is_int($data['amount_minor'] ?? null) ? $data['amount_minor'] : 0, $currency),
            paymentId: $paymentId === null ? null : new PaymentId($paymentId),
            refundId: $refundId === null ? null : new RefundId($refundId),
            storedValueAccountId: self::nullableString($data, 'stored_value_account_id'),
            storedValueJournalId: self::nullableString($data, 'stored_value_journal_id'),
            details: $details,
        );
    }

    public static function excerptToJson(OrderExcerpt $excerpt): string
    {
        return self::encodeObject([
            'order_id' => $excerpt->orderId->value,
            'order_version' => $excerpt->orderVersion,
            'currency' => $excerpt->currency->code,
            'subtotal_minor' => $excerpt->totals->subtotal->amountMinor,
            'discount_total_minor' => $excerpt->totals->discountTotal->amountMinor,
            'delivery_total_minor' => $excerpt->totals->deliveryTotal->amountMinor,
            'tax_total_minor' => $excerpt->totals->taxTotal->amountMinor,
            'total_minor' => $excerpt->totals->total->amountMinor,
            'amount_due_minor' => $excerpt->amountDue->amountMinor,
            'delivery_method' => $excerpt->delivery?->methodCode,
            'lines' => array_map(static fn (OrderExcerptLine $line): array => [
                'id' => $line->id->value,
                'sellable_ref' => (string) $line->sellable,
                'sku' => $line->sku,
                'quantity' => $line->quantity,
                'unit_price_minor' => $line->unitPrice->amountMinor,
                'subtotal_minor' => $line->subtotal->amountMinor,
                'total_minor' => $line->total->amountMinor,
                'is_new' => $line->isNew,
            ], $excerpt->lines),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private static function decodeAddLine(array $payload): AddLineAction
    {
        $override = $payload['unit_price_override'] ?? null;
        $unitPrice = null;

        if (is_array($override)) {
            $unitPrice = Money::ofMinor(self::int($override, 'amount_minor'), self::string($override, 'currency'));
        }

        $seller = self::nullableString($payload, 'seller_ref');

        return new AddLineAction(
            sellable: SellableRef::fromString(self::string($payload, 'sellable_ref')),
            quantity: self::int($payload, 'quantity'),
            unitPriceOverride: $unitPrice,
            sellerRef: $seller === null ? null : SellerRef::fromString($seller),
        );
    }

    /** @param  array<string, mixed>  $payload */
    private static function decodeOverrideUnitPrice(array $payload): OverrideUnitPriceAction
    {
        $price = $payload['unit_price'] ?? null;

        if (! is_array($price)) {
            throw new LogicException("'unit_price' must be an object");
        }

        return new OverrideUnitPriceAction(
            line: new OrderLineId(self::string($payload, 'line_id')),
            unitPrice: Money::ofMinor(self::int($price, 'amount_minor'), self::string($price, 'currency')),
            reason: self::nullableString($payload, 'reason'),
        );
    }

    /** @param  array<string, mixed>  $payload */
    private static function decodeChangeShippingMethod(array $payload): ChangeShippingMethodAction
    {
        $methodCode = self::nullableString($payload, 'method_code');
        $overrideRaw = $payload['charge_override'] ?? null;
        $charge = null;

        if (is_array($overrideRaw)) {
            $taxIncluded = $overrideRaw['tax_included'] ?? null;

            if (! is_bool($taxIncluded)) {
                throw new LogicException("'tax_included' must be a boolean");
            }

            $charge = new DeliveryCharge(
                methodCode: self::string($overrideRaw, 'method_code'),
                name: self::string($overrideRaw, 'name'),
                carrier: self::string($overrideRaw, 'carrier'),
                price: Money::ofMinor(self::int($overrideRaw, 'price_minor'), self::string($overrideRaw, 'currency')),
                taxIncluded: $taxIncluded,
            );
        }

        return new ChangeShippingMethodAction($methodCode, $charge);
    }

    /** @param  array<string, mixed>  $payload */
    private static function decodeReplaceAdjustments(array $payload): ReplaceAdjustmentsAction
    {
        $raw = $payload['adjustments'] ?? null;

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new LogicException("'adjustments' must be a list");
        }

        $adjustments = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                throw new LogicException('adjustment item must be an object');
            }

            $adjustments[] = new Adjustment(
                target: AdjustmentTarget::from(self::string($item, 'target')),
                lineId: self::nullableString($item, 'line_id'),
                amount: Money::ofMinor(self::int($item, 'amount_minor'), self::string($item, 'currency')),
                source: self::string($item, 'source'),
                explanation: self::string($item, 'explanation'),
                promotionId: self::nullableString($item, 'promotion_id'),
                couponCode: self::nullableString($item, 'coupon_code'),
            );
        }

        return new ReplaceAdjustmentsAction($adjustments);
    }

    /** @param  array<array-key, mixed>  $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException("'{$key}' is missing or not a string");
    }

    /** @param  array<array-key, mixed>  $data */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return match (true) {
            $value === null => null,
            is_string($value) => $value,
            default => throw new LogicException("'{$key}' is not a string"),
        };
    }

    /** @param  array<array-key, mixed>  $data */
    private static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : throw new LogicException("'{$key}' is missing or not an integer");
    }
}

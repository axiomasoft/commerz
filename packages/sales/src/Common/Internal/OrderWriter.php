<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\ActorRef;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Data\TaxLine;
use Commerz\Quotes\Enums\AdjustmentTarget;
use Commerz\Sales\Checkout\Exceptions\DuplicateOrderNumber;
use Commerz\Sales\OrderChanges\Data\OrderExcerpt;
use Commerz\Sales\OrderChanges\Data\OrderExcerptLine;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelView;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\ConstraintNames;

/**
 * The only writer of new `orders` and `order_lines` rows (ORD-01): the snapshot of an accepted quote (ORD-02). An
 * order line takes the id of the cart line it was quoted for, so the line ids of the quote's adjustments and taxes stay
 * valid in the order. Called only by the placement, under the lock of the cart.
 */
#[Internal]
final readonly class OrderWriter
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
    ) {}

    /**
     * Writes a placed, unfulfilled order whose amount due is its total: unpaid, or paid when it owes nothing, so a free
     * order is never expired as unpaid. A savepoint guards the insert, so a
     * taken number fails only this operation, not the host's transaction.
     *
     * @throws DuplicateOrderNumber
     */
    public function insert(
        OrderId $id,
        string $number,
        ChannelView $channel,
        CartRow $cart,
        QuoteResult $quote,
        ?CustomerProfile $customer,
        ?ActorRef $actor,
        DateTimeImmutable $at,
        ?DateTimeImmutable $paymentDueAt,
    ): void {
        $now = $at->format('Y-m-d H:i:s.uP');
        $totals = $quote->totals;
        $total = self::minor($totals->total, $cart);
        $quantity = array_sum(array_map(static fn (QuotedLine $line): int => $line->quantity, $quote->lines));
        [, $payment] = OrderStatus::derive(OrderState::Placed, $quantity, 0, 0, 0, $total, 0, 0, 0);

        $order = [
            'id' => $id->value,
            'number' => $number,
            'store_id' => $channel->store->value,
            'channel_id' => $channel->id->value,
            'cart_id' => $cart->id,
            'origin' => $cart->kind->value,
            'state' => OrderState::Placed->value,
            'payment_state' => $payment->value,
            'fulfillment_state' => FulfillmentState::Unfulfilled->value,
            'currency' => $cart->currency->code,
            'locale' => $cart->locale,
            'customer_ref' => $cart->customer === null ? null : (string) $cart->customer,
            'customer_groups' => SalesCodec::stringsToJson($cart->groups),
            'customer_email' => $customer?->email,
            'customer_name' => $customer?->name,
            'codes' => SalesCodec::stringsToJson($cart->codes),
            'shipping_address' => $cart->shippingAddress === null ? null : SalesCodec::addressToJson($cart->shippingAddress),
            'billing_address' => $cart->billingAddress === null ? null : SalesCodec::addressToJson($cart->billingAddress),
            'delivery' => $quote->delivery === null ? null : SalesCodec::deliveryToJson($quote->delivery),
            'order_adjustments' => SalesCodec::adjustmentsToJson($quote->orderAdjustments),
            'delivery_adjustments' => SalesCodec::adjustmentsToJson($quote->deliveryAdjustments),
            'delivery_tax_lines' => SalesCodec::taxLinesToJson(array_values(array_filter(
                $quote->taxLines,
                static fn (TaxLine $tax): bool => $tax->target === AdjustmentTarget::Delivery,
            ))),
            'subtotal_minor' => self::minor($totals->subtotal, $cart),
            'discount_total_minor' => self::minor($totals->discountTotal, $cart),
            'delivery_total_minor' => self::minor($totals->deliveryTotal, $cart),
            'tax_total_minor' => self::minor($totals->taxTotal, $cart),
            'total_minor' => $total,
            'amount_due_minor' => $total,
            'quote_fingerprint' => $quote->fingerprint,
            'payment_due_at' => $paymentDueAt?->format('Y-m-d H:i:s.uP'),
            'placed_at' => $now,
            'created_by_ref' => $actor === null ? null : (string) $actor,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $lines = array_map(
            static fn (QuotedLine $line, int $index): array => self::line($id, $line, $index + 1, $cart),
            $quote->lines,
            array_keys($quote->lines),
        );

        $this->transactions->run(function () use ($order, $lines, $channel, $number): void {
            $db = $this->connections->connection($this->config->connection);
            $orders = $this->tables->table('orders');

            try {
                $db->table($orders)->insert($order);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), ConstraintNames::unique($orders, ['store_id', 'number']))) {
                    throw new DuplicateOrderNumber($channel->store->value, $number);
                }

                throw $e;
            }

            $db->table($this->tables->table('order_lines'))->insert($lines);
        });
    }

    /**
     * Atomically applies a confirmed `OrderChange` snapshot to `orders` and `order_lines` under the order lock,
     * guarded by OCC (`WHERE id = ? AND version = ?`, `INV-OMS-001`). Never writes line ledger counters (`ORD-03`).
     *
     * @return bool false when the order's version no longer matches `$expectedVersion`
     */
    public function applyOrderChange(
        OrderId $orderId,
        int $expectedVersion,
        OrderExcerpt $after,
        string $quoteFingerprint,
        DateTimeImmutable $at,
    ): bool {
        $db = $this->connections->connection($this->config->connection);
        $orders = $this->tables->table('orders');
        $orderLines = $this->tables->table('order_lines');
        $now = PgTimestamp::format($at);

        $updated = $db->table($orders)
            ->where('id', $orderId->value)
            ->where('version', $expectedVersion)
            ->update([
                'version' => $expectedVersion + 1,
                'delivery' => $after->delivery === null ? null : SalesCodec::deliveryToJson($after->delivery),
                'order_adjustments' => SalesCodec::adjustmentsToJson($after->orderAdjustments),
                'delivery_adjustments' => SalesCodec::adjustmentsToJson($after->deliveryAdjustments),
                'delivery_tax_lines' => SalesCodec::taxLinesToJson($after->deliveryTaxLines),
                'subtotal_minor' => $after->totals->subtotal->amountMinor,
                'discount_total_minor' => $after->totals->discountTotal->amountMinor,
                'delivery_total_minor' => $after->totals->deliveryTotal->amountMinor,
                'tax_total_minor' => $after->totals->taxTotal->amountMinor,
                'total_minor' => $after->totals->total->amountMinor,
                'amount_due_minor' => $after->amountDue->amountMinor,
                'quote_fingerprint' => $quoteFingerprint,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            return false;
        }

        $existingIds = [];

        foreach ($db->table($orderLines)->where('order_id', $orderId->value)->pluck('id') as $existingId) {
            if (is_string($existingId)) {
                $existingIds[$existingId] = true;
            }
        }

        $keptIds = [];
        $inserts = [];

        foreach ($after->lines as $index => $line) {
            $position = $index + 1;
            $lineId = $line->id->value;
            $keptIds[$lineId] = true;

            if (isset($existingIds[$lineId])) {
                $db->table($orderLines)
                    ->where('id', $lineId)
                    ->where('order_id', $orderId->value)
                    ->update([
                        'position' => $position,
                        'quantity' => $line->quantity,
                        'unit_price_minor' => $line->unitPrice->amountMinor,
                        'compare_at_minor' => $line->compareAt?->amountMinor,
                        'tax_included' => $line->taxIncluded,
                        'price_source' => $line->priceSource,
                        'subtotal_minor' => $line->subtotal->amountMinor,
                        'total_minor' => $line->total->amountMinor,
                        'adjustments' => SalesCodec::adjustmentsToJson($line->adjustments),
                        'tax_lines' => SalesCodec::taxLinesToJson($line->taxLines),
                    ]);
            } else {
                $inserts[] = self::excerptLine($orderId, $line, $position);
            }
        }

        $removedIds = array_keys(array_diff_key($existingIds, $keptIds));

        if ($removedIds !== []) {
            $db->table($orderLines)
                ->where('order_id', $orderId->value)
                ->whereIn('id', $removedIds)
                ->delete();
        }

        if ($inserts !== []) {
            $db->table($orderLines)->insert($inserts);
        }

        return true;
    }

    /** @return array<string, mixed> */
    private static function excerptLine(OrderId $order, OrderExcerptLine $line, int $position): array
    {
        return [
            'id' => $line->id->value,
            'order_id' => $order->value,
            'position' => $position,
            'sellable_ref' => (string) $line->sellable,
            'seller_ref' => $line->sellerRef === null ? null : (string) $line->sellerRef,
            'sku' => $line->sku,
            'name' => $line->name,
            'kind' => $line->kind->value,
            'requires_shipping' => $line->requiresShipping,
            'options' => SalesCodec::optionsToJson($line->options),
            'tax_class' => $line->taxClass,
            'weight_grams' => $line->weightGrams,
            'quantity' => $line->quantity,
            'currency' => $line->unitPrice->currency->code,
            'unit_price_minor' => $line->unitPrice->amountMinor,
            'compare_at_minor' => $line->compareAt?->amountMinor,
            'tax_included' => $line->taxIncluded,
            'price_source' => $line->priceSource,
            'subtotal_minor' => $line->subtotal->amountMinor,
            'total_minor' => $line->total->amountMinor,
            'adjustments' => SalesCodec::adjustmentsToJson($line->adjustments),
            'tax_lines' => SalesCodec::taxLinesToJson($line->taxLines),
        ];
    }

    /** @return array<string, mixed> */
    private static function line(OrderId $order, QuotedLine $line, int $position, CartRow $cart): array
    {
        $sellable = $line->sellable;

        return [
            'id' => $line->lineId,
            'order_id' => $order->value,
            'position' => $position,
            'sellable_ref' => (string) $sellable->ref,
            'seller_ref' => $sellable->sellerRef === null ? null : (string) $sellable->sellerRef,
            'sku' => $sellable->sku,
            'name' => $sellable->name,
            'kind' => $sellable->kind->value,
            'requires_shipping' => $sellable->requiresShipping,
            'options' => SalesCodec::optionsToJson($sellable->options),
            'tax_class' => $sellable->taxClass,
            'weight_grams' => $sellable->weightGrams,
            'quantity' => $line->quantity,
            'currency' => $cart->currency->code,
            'unit_price_minor' => self::minor($line->unitPrice, $cart),
            'compare_at_minor' => $line->compareAt === null ? null : self::minor($line->compareAt, $cart),
            'tax_included' => $line->taxIncluded,
            'price_source' => $line->priceSource,
            'subtotal_minor' => self::minor($line->subtotal, $cart),
            'total_minor' => self::minor($line->total, $cart),
            'adjustments' => SalesCodec::adjustmentsToJson($line->adjustments),
            'tax_lines' => SalesCodec::taxLinesToJson($line->taxLines),
        ];
    }

    /** An amount of the order: in the one currency of the cart (CTX-01), which every money column of the order shares. */
    private static function minor(Money $money, CartRow $cart): int
    {
        if (! $money->currency->equals($cart->currency)) {
            throw new LogicException("The quote of cart '{$cart->id}' holds {$money->currency->code}, not the cart's {$cart->currency->code}.");
        }

        return $money->amountMinor;
    }
}

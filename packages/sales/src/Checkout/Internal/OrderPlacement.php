<?php

declare(strict_types=1);

namespace Commerz\Sales\Checkout\Internal;

use Commerz\Identity\SellableRef;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Plugins\Contracts\Contributions;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\ConsumePromotionUsage;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Checkout\Contracts\Checkout;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Checkout\Exceptions\CartAlreadyPlaced;
use Commerz\Sales\Checkout\Exceptions\CartChanged;
use Commerz\Sales\Checkout\Exceptions\CartEmpty;
use Commerz\Sales\Checkout\Exceptions\DeliveryRequired;
use Commerz\Sales\Checkout\Exceptions\QuoteChanged;
use Commerz\Sales\Common\Internal\CartQuoter;
use Commerz\Sales\Common\Internal\CartRow;
use Commerz\Sales\Common\Internal\CartRows;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderRows;
use Commerz\Sales\Common\Internal\OrderWriter;
use Commerz\Sales\Common\Internal\SalesConfig;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Data\OrderNumbering;
use Commerz\Sales\Numbering\Exceptions\InvalidOrderNumber;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Sales\Orders\Data\OrderView;
use Commerz\Sales\Orders\Events\OrderPlaced;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * The placement protocol of knowledge/sales-design#placement. Everything that reads — the quote, the channel, the
 * store, the customer, the number generator — runs before the transaction; inside it, in the global lock order:
 * receipt → cart `for update` → order snapshot with its number → stock (reserve) → promotion usage (consume) →
 * conversion of the cart, journal, outbox. A failure of any step rolls all of them back (EXT-01).
 */
final readonly class OrderPlacement implements Checkout
{
    private const string SCOPE = 'sales.place_order';

    public function __construct(
        private Transactions $transactions,
        private OperationReceipts $receipts,
        private CommittedEvents $events,
        private Outbox $outbox,
        private Contributions $contributions,
        private Channels $channels,
        private Stores $stores,
        private CustomerDirectory $customers,
        private StockReservations $stock,
        private PromotionUsage $usage,
        private SalesConfig $config,
        private CartRows $carts,
        private CartQuoter $quoter,
        private OrderRows $orders,
        private OrderWriter $writer,
        private OrderJournal $journal,
    ) {}

    public function place(PlaceOrder $command): OrderView
    {
        $cart = $this->carts->find($command->cart->value) ?? throw new CartNotFound($command->cart->value);

        // A placed cart is a repeat: the receipt answers it with the order, or the lock of the cart refuses it.
        if ($cart->state === CartState::Converted) {
            if ($command->idempotencyKey === null) {
                throw new CartAlreadyPlaced($cart->id, $this->orders->idOfCart($cart->id));
            }

            return $this->transact($command, $cart, fn (): OrderView => $this->refuseConverted($this->carts->lock($cart->id)));
        }

        $placement = $this->prepare($command, $cart);

        return $this->transact($command, $cart, fn (): OrderView => $this->write($command, $placement));
    }

    /**
     * Steps 1–3: everything that only reads, so that no port is called under the locks of the transaction.
     *
     * @throws CartEmpty|SellableNotPurchasable|ShippingAddressRequired|DeliveryRequired|QuoteChanged
     */
    private function prepare(PlaceOrder $command, CartRow $cart): Placement
    {
        if ($cart->lines === []) {
            throw new CartEmpty($cart->id);
        }

        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // The same calculation as `Carts::quote`, so the total the buyer accepted is the total checked here.
        $quote = $this->quoter->quoteCart($cart, $at)->quote;
        $ships = false;

        foreach ($quote->lines as $line) {
            if (! $line->sellable->purchasable) {
                throw new SellableNotPurchasable($line->sellable->ref, $line->sellable->unavailableReason); // CTX-01
            }

            $ships = $ships || $line->sellable->requiresShipping;
        }

        if ($ships && $cart->shippingAddress === null) {
            throw new ShippingAddressRequired;
        }

        if ($ships && $this->config->requireDelivery && $quote->delivery === null) {
            throw new DeliveryRequired($cart->id);
        }

        if (! $command->expectedTotal->equals($quote->total)) {
            throw new QuoteChanged($command->expectedTotal, $quote->total);
        }

        $channel = $this->channels->assertActive($cart->channel, $cart->currency);

        return new Placement(
            $cart,
            $quote,
            $quote->appliedPromotions(),
            $channel,
            $this->stores->find($channel->store),
            $cart->customer === null ? null : $this->customers->find($cart->customer),
            $this->contributions->get(OrderNumberGenerator::KIND, $this->config->numberGenerator, OrderNumberGenerator::class),
            $at,
        );
    }

    /**
     * Step 4: the transaction, behind the receipt of the idempotency key when there is one. The fingerprint is the
     * cart, its version and the accepted total: not the quote's fingerprint, which carries the time of the quote, and
     * the conversion keeps the version, so an honest repeat matches.
     *
     * @param  callable(): OrderView  $work
     */
    private function transact(PlaceOrder $command, CartRow $cart, callable $work): OrderView
    {
        return $this->transactions->run(function () use ($command, $cart, $work): OrderView {
            if ($command->idempotencyKey === null) {
                return $work();
            }

            return $this->receipts->once(
                self::SCOPE,
                $command->idempotencyKey,
                Fingerprint::of([
                    'cart' => $cart->id,
                    'version' => $cart->version,
                    'expected_total' => [$command->expectedTotal->amountMinor, $command->expectedTotal->currency->code],
                ]),
                $work,
                static fn (OrderView $order): array => ['order_id' => $order->id->value],
                fn (array $stored): OrderView => $this->orders->get(self::storedOrderId($stored)),
            );
        }, lockTimeoutMs: 3000);
    }

    /** Steps 5–9, under the lock of the cart. */
    private function write(PlaceOrder $command, Placement $placement): OrderView
    {
        $prepared = $placement->cart;
        $cart = $this->carts->lock($prepared->id);

        if ($cart->state === CartState::Converted) {
            $this->refuseConverted($cart);
        }

        // The quote was made outside the transaction: a change of the cart since then makes it stale.
        if ($cart->version !== $prepared->version) {
            throw new CartChanged($cart->id, $prepared->version, $cart->version);
        }

        $id = OrderId::generate();
        $number = $this->number($placement, $id);
        $at = $placement->at;
        $dueMinutes = $this->config->paymentDueMinutes;

        $this->writer->insert(
            $id,
            $number,
            $placement->channel,
            $cart,
            $placement->quote,
            $placement->customer,
            $command->actor,
            $at,
            $cart->kind === CartKind::Cart && $dueMinutes !== null ? $at->modify("+{$dueMinutes} minutes") : null,
        );

        $reserve = self::reserveLines($placement->quote);

        if ($reserve !== []) {
            // No deadline: an unpaid order expires through sales (D32), never by the stock giving the units away.
            $this->stock->reserve(new ReserveStock('order:'.$id->value, $placement->channel->id, $reserve, expiresAt: null));
        }

        if ($placement->applied !== []) {
            $this->usage->consume(new ConsumePromotionUsage('order:'.$id->value, $cart->customer, $placement->applied));
        }

        $this->carts->convert($cart);

        $total = $placement->quote->total;
        $this->journal->append($id, 'placed', $command->actor, [
            'number' => $number,
            'total_minor' => $total->amountMinor,
            'currency' => $total->currency->code,
        ]);
        $this->outbox->record(IntegrationEvent::of('sales.order_placed', 'order:'.$id->value, [
            'order_id' => $id->value,
            'number' => $number,
            'channel_id' => $placement->channel->id->value,
            'customer_ref' => $cart->customer === null ? null : (string) $cart->customer,
            'total_minor' => $total->amountMinor,
            'currency' => $total->currency->code,
        ]));
        $this->events->dispatch(new OrderPlaced($id, $number));

        return $this->orders->get($id->value);
    }

    /** @throws CartAlreadyPlaced */
    private function refuseConverted(CartRow $cart): never
    {
        throw new CartAlreadyPlaced($cart->id, $this->orders->idOfCart($cart->id));
    }

    /** The number is drawn inside the transaction (D34) and checked before it reaches the table. @throws InvalidOrderNumber */
    private function number(Placement $placement, OrderId $id): string
    {
        $number = $placement->generator->next(new OrderNumbering($placement->store, $placement->channel->id, $id, $placement->at));

        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/', $number) !== 1) {
            throw new InvalidOrderNumber($placement->generator->key(), $number);
        }

        return $number;
    }

    /**
     * The physical lines only, one per sellable: services and digital goods hold no stock.
     *
     * @return list<ReserveLine>
     */
    private static function reserveLines(QuoteResult $quote): array
    {
        $quantities = [];
        $sellables = [];

        foreach ($quote->lines as $line) {
            if ($line->sellable->kind !== SellableKind::Physical) {
                continue;
            }

            $key = (string) $line->sellable->ref;
            $quantities[$key] = ($quantities[$key] ?? 0) + $line->quantity;
            $sellables[$key] = $line->sellable->ref;
        }

        return array_map(
            static fn (SellableRef $sellable, int $quantity): ReserveLine => new ReserveLine($sellable, $quantity),
            $sellables,
            $quantities,
        );
    }

    /** @param  array<array-key, mixed>  $stored */
    private static function storedOrderId(array $stored): string
    {
        $id = $stored['order_id'] ?? null;

        return is_string($id) ? $id : throw new LogicException('The receipt of a placement holds no order id.');
    }
}

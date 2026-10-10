<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Internal;

use Closure;
use Commerz\Configuration\CommerzConfig;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Data\ClearDelivery;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\ListCarts;
use Commerz\Sales\Carts\Data\RemoveCartLine;
use Commerz\Sales\Carts\Data\RemoveCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Data\SetCartLineQuantity;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Common\Internal\CartLines;
use Commerz\Sales\Common\Internal\CartQuoter;
use Commerz\Sales\Common\Internal\CartRow;
use Commerz\Sales\Common\Internal\CartRows;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Common\Internal\SalesCodec;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

/**
 * Every change takes the cart row `for update`, so it is the only writer of its cart and of its lines; sellables are
 * read before the transaction. A change that finds nothing to change leaves the version alone.
 */
final readonly class DatabaseCarts implements Carts
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Channels $channels,
        private DeliveryOptions $delivery,
        private CartRows $rows,
        private CartLines $lines,
        private CartQuoter $quoter,
    ) {}

    public function create(CreateCart $command): CartView
    {
        return $this->transactions->run(function () use ($command): CartView {
            $this->channels->assertActive($command->context->channel, $command->context->currency);
            $id = $this->rows->insert($command->context, CartKind::Cart, $command->actor);

            return $this->rows->view($this->rows->get($id));
        });
    }

    public function addLine(AddCartLine $command): CartView
    {
        $cart = CartRows::assertOpen($this->rows->get($command->cart->value));
        $this->lines->assertPurchasable($cart, $command->sellable);

        return $this->mutate($command->cart, function (CartRow $cart) use ($command): array {
            $this->lines->add($cart, $command->sellable, $command->quantity);

            return [];
        });
    }

    public function setLineQuantity(SetCartLineQuantity $command): CartView
    {
        return $this->mutate($command->cart, fn (CartRow $cart): ?array => $this->lines->setQuantity($cart, $command->lineId, $command->quantity) ? [] : null);
    }

    public function removeLine(RemoveCartLine $command): CartView
    {
        return $this->mutate($command->cart, function (CartRow $cart) use ($command): array {
            $this->lines->remove($cart, $command->lineId);

            return [];
        });
    }

    public function enterCode(EnterCode $command): CartView
    {
        return $this->mutate($command->cart, static fn (CartRow $cart): ?array => in_array($command->code, $cart->codes, true)
            ? null
            : ['codes' => SalesCodec::stringsToJson([...$cart->codes, $command->code])]);
    }

    public function removeCode(RemoveCode $command): CartView
    {
        return $this->mutate($command->cart, static fn (CartRow $cart): ?array => ! in_array($command->code, $cart->codes, true)
            ? null
            : ['codes' => SalesCodec::stringsToJson(array_values(array_filter($cart->codes, static fn (string $code): bool => $code !== $command->code)))]);
    }

    public function setCustomer(SetCartCustomer $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart) use ($command): ?array {
            $sameCustomer = $cart->customer === null || $command->customer === null
                ? $cart->customer === $command->customer
                : $cart->customer->equals($command->customer);

            if ($sameCustomer && $cart->groups === $command->groups) {
                return null;
            }

            return [
                'customer_ref' => $command->customer === null ? null : (string) $command->customer,
                'customer_groups' => SalesCodec::stringsToJson($command->groups),
            ];
        });
    }

    public function setAddress(SetCartAddress $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart) use ($command): ?array {
            $current = $command->role === AddressRole::Shipping ? $cart->shippingAddress : $cart->billingAddress;

            $same = $current === null || $command->address === null
                ? $current === $command->address
                : SalesCodec::addressToJson($current) === SalesCodec::addressToJson($command->address);

            if ($same) {
                return null;
            }

            return [
                $command->role === AddressRole::Shipping ? 'shipping_address' : 'billing_address' => $command->address === null ? null : SalesCodec::addressToJson($command->address),
            ];
        });
    }

    public function selectDelivery(SelectDelivery $command): CartView
    {
        return $this->mutate($command->cart, static fn (CartRow $cart): ?array => $cart->deliveryMethod === $command->methodCode ? null : ['delivery_method' => $command->methodCode]);
    }

    public function clearDelivery(ClearDelivery $command): CartView
    {
        return $this->mutate($command->cart, static fn (CartRow $cart): ?array => $cart->deliveryMethod === null ? null : ['delivery_method' => null]);
    }

    public function find(CartId $id): CartView
    {
        return $this->rows->view($this->rows->get($id->value));
    }

    public function list(ListCarts $query): Page
    {
        $builder = $this->db()->table($this->tables->table('carts'))
            ->select('*')
            ->selectRaw('created_at::text as created_position')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1);

        if ($query->kind instanceof CartKind) {
            $builder->where('kind', $query->kind->value);
        }

        if ($query->state instanceof CartState) {
            $builder->where('state', $query->state->value);
        }

        if ($query->customer !== null) {
            $builder->where('customer_ref', (string) $query->customer);
        }

        if ($query->after instanceof Cursor) {
            self::after($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            array_map($this->rows->view(...), $this->rows->hydrate($rows)),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'created_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    public function quote(CartId $id): QuoteResult
    {
        return $this->quoter->quoteCart($this->rows->get($id->value), self::now())->quote;
    }

    public function deliveryOptions(CartId $id): array
    {
        $cart = $this->rows->get($id->value);
        $address = $cart->shippingAddress ?? throw new ShippingAddressRequired;

        if ($cart->lines === []) {
            return [];
        }

        $at = self::now();
        $quoted = $this->quoter->quoteCart($cart, $at, withDelivery: false);
        $value = Money::zero($cart->currency);
        $refs = [];

        foreach ($quoted->quote->lines as $line) {
            // The tariff threshold uses the whole cart after discounts, as Shipping's DeliveryStage does.
            // The parcel's weight and sellable refs still describe only the lines that ship.
            $value = $value->plus($line->subtotal);

            foreach ($line->adjustments as $adjustment) {
                $value = $value->plus($adjustment->amount);
            }

            if ($line->sellable->requiresShipping) {
                $refs[] = $line->sellable->ref;
            }
        }

        if ($refs === []) {
            return [];
        }

        return $this->delivery->options(new DeliveryRequest(
            $cart->context($at),
            $address,
            new Parcel(CartQuoter::shippingWeight($cart->lines, $quoted->snapshots), $value->isNegative() ? Money::zero($cart->currency) : $value),
            $refs,
        ));
    }

    /**
     * Runs a change under the lock of the open cart.
     *
     * @param  Closure(CartRow): (array<string, mixed>|null)  $change  writes the lines itself and returns the columns of the cart to update; null when nothing changed
     */
    private function mutate(CartId $id, Closure $change): CartView
    {
        return $this->transactions->run(function () use ($id, $change): CartView {
            $cart = $this->rows->lockOpen($id->value);
            $columns = $change($cart);

            if ($columns !== null) {
                $this->rows->bump($cart, $columns);
            }

            return $this->rows->view($this->rows->get($id->value));
        });
    }

    /** The keyset is (created_at, id), newest first; the timestamp is PostgreSQL's own text of it, so no precision is lost. */
    private static function after(Builder $builder, Cursor $cursor): void
    {
        $parts = explode('|', $cursor->position());

        if (count($parts) !== 2
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}\z/', $parts[0]) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $builder->whereRaw('(created_at, id) < (?::timestamptz, ?::uuid)', $parts);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

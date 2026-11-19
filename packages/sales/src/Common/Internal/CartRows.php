<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CartLineView;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotOpen;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/** The only reader and writer of the `carts` row; the lines are written by `CartLines`. */
#[Internal]
final readonly class CartRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    public function find(string $id): ?CartRow
    {
        $row = $this->db()->table($this->tables->table('carts'))->where('id', $id)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** @throws CartNotFound */
    public function get(string $id): CartRow
    {
        return $this->find($id) ?? throw new CartNotFound($id);
    }

    /** Takes the cart `for update`: the one lock of every cart mutation and of the placement. @throws CartNotFound */
    public function lock(string $id): CartRow
    {
        $row = $this->db()->table($this->tables->table('carts'))->where('id', $id)->lockForUpdate()->first();

        return $this->hydrate([$row ?? throw new CartNotFound($id)])[0];
    }

    /** @throws CartNotFound|CartNotOpen */
    public function lockOpen(string $id): CartRow
    {
        $cart = $this->lock($id);

        return self::assertOpen($cart);
    }

    /** @throws CartNotOpen */
    public static function assertOpen(CartRow $cart): CartRow
    {
        return $cart->state === CartState::Open ? $cart : throw new CartNotOpen($cart->id, $cart->state->value);
    }

    /**
     * Inserts an empty open cart of the context and returns its id.
     *
     * @param  array<string, mixed>  $columns  more columns of `carts` set from the start (a draft's codes, addresses, delivery method)
     */
    public function insert(CommerceContext $context, CartKind $kind, ?ActorRef $actor, array $columns = []): string
    {
        $id = Uuid7::generate();
        $now = Clock::now();

        $this->db()->table($this->tables->table('carts'))->insert([
            'id' => $id,
            'kind' => $kind->value,
            'state' => CartState::Open->value,
            'channel_id' => $context->channel->value,
            'currency' => $context->currency->code,
            'locale' => $context->locale,
            'customer_ref' => $context->customer === null ? null : (string) $context->customer,
            'customer_groups' => SalesCodec::stringsToJson($context->customerGroups),
            'created_by_ref' => $actor === null ? null : (string) $actor,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $columns);

        return $id;
    }

    /**
     * Writes the changed columns of a locked cart and moves its version on.
     *
     * @param  array<string, mixed>  $changes  columns of `carts`; none for a change of the lines alone
     */
    public function bump(CartRow $cart, array $changes = []): void
    {
        $this->db()->table($this->tables->table('carts'))
            ->where('id', $cart->id)
            ->update($changes + ['version' => $cart->version + 1, 'updated_at' => Clock::now()]);
    }

    /**
     * Marks a locked cart placed. The version stays: a repeated placement with the same idempotency key must find the
     * fingerprint it was first made with.
     */
    public function convert(CartRow $cart): void
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('carts'))
            ->where('id', $cart->id)
            ->update(['state' => CartState::Converted->value, 'converted_at' => $now, 'updated_at' => $now]);
    }

    public function view(CartRow $cart): CartView
    {
        return new CartView(
            new CartId($cart->id),
            $cart->kind,
            $cart->state,
            $cart->channel,
            $cart->currency,
            $cart->locale,
            $cart->customer,
            $cart->groups,
            array_map(static fn (CartLineRow $line): CartLineView => new CartLineView($line->id, $line->sellable, $line->quantity), $cart->lines),
            $cart->codes,
            $cart->shippingAddress,
            $cart->billingAddress,
            $cart->deliveryMethod,
            $cart->version,
            $cart->createdBy,
            $cart->createdAt,
            $cart->updatedAt,
        );
    }

    /**
     * Builds the carts of the rows, reading all their lines in one query.
     *
     * @param  list<object>  $rows
     * @return list<CartRow>
     */
    public function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $lines = [];

        foreach ($this->db()->table($this->tables->table('cart_lines'))
            ->whereIn('cart_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->orderBy('position')
            ->get() as $line) {
            $lines[Rows::string($line, 'cart_id')][] = new CartLineRow(
                Rows::string($line, 'id'),
                SellableRef::fromString(Rows::string($line, 'sellable_ref')),
                Rows::int($line, 'quantity'),
                Rows::int($line, 'position'),
            );
        }

        return array_map(static function (object $row) use ($lines): CartRow {
            $customer = Rows::nullableString($row, 'customer_ref');
            $createdBy = Rows::nullableString($row, 'created_by_ref');
            $shipping = Rows::nullableString($row, 'shipping_address');
            $billing = Rows::nullableString($row, 'billing_address');

            return new CartRow(
                Rows::string($row, 'id'),
                CartKind::from(Rows::string($row, 'kind')),
                CartState::from(Rows::string($row, 'state')),
                new ChannelId(Rows::string($row, 'channel_id')),
                Currency::of(Rows::string($row, 'currency')),
                Rows::string($row, 'locale'),
                $customer === null ? null : CustomerRef::fromString($customer),
                SalesCodec::stringsFromJson(Rows::string($row, 'customer_groups')),
                SalesCodec::stringsFromJson(Rows::string($row, 'codes')),
                $shipping === null ? null : SalesCodec::addressFromJson($shipping),
                $billing === null ? null : SalesCodec::addressFromJson($billing),
                Rows::nullableString($row, 'delivery_method'),
                $createdBy === null ? null : ActorRef::fromString($createdBy),
                Rows::int($row, 'version'),
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
                $lines[Rows::string($row, 'id')] ?? [],
            );
        }, $rows);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

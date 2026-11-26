<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartDeliveryGroup;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CartView;
use Commerz\Sales\Carts\Data\ClearDelivery;
use Commerz\Sales\Carts\Data\ClearGroupDelivery;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\EnterCode;
use Commerz\Sales\Carts\Data\ListCarts;
use Commerz\Sales\Carts\Data\PricedDeliveryGroup;
use Commerz\Sales\Carts\Data\RemoveCartLine;
use Commerz\Sales\Carts\Data\RemoveCode;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SelectGroupDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Data\SetCartLineQuantity;
use Commerz\Sales\Carts\Data\SetCartPickupPoint;
use Commerz\Sales\Carts\Data\SplitDeliveryQuote;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Sales\Carts\Exceptions\CartLineNotFound;
use Commerz\Sales\Carts\Exceptions\ShippingAddressRequired;
use Commerz\Sales\Common\Internal\CartLines;
use Commerz\Sales\Common\Internal\CartQuoter;
use Commerz\Sales\Common\Internal\CartRow;
use Commerz\Sales\Common\Internal\CartRows;
use Commerz\Sales\Common\Internal\SalesCodec;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Time\Contracts\Clock;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/**
 * Every change takes the cart row `for update`, so it is the only writer of its cart and of its lines; sellables are
 * read before the transaction. A change that finds nothing to change leaves the version alone.
 */
#[Internal]
final readonly class DatabaseCarts implements Carts
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Channels $channels,
        private DeliveryOptions $delivery,
        private CartRows $rows,
        private CartLines $lines,
        private CartQuoter $quoter,
        private CustomerDirectory $customers,
        private Clock $clock,
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
        $snapshot = $this->lines->assertPurchasable($cart, $command->sellable);

        return $this->mutate($command->cart, function (CartRow $cart) use ($command, $snapshot): array {
            $this->lines->add($cart, $command->sellable, $command->quantity, $snapshot->sellerRef);

            return [];
        });
    }

    public function setLineQuantity(SetCartLineQuantity $command): CartView
    {
        return $this->mutate($command->cart, function (CartRow $cart) use ($command): ?array {
            if (! $this->lines->setQuantity($cart, $command->lineId, $command->quantity)) {
                return null;
            }

            if ($command->quantity === 0) {
                return self::pruneLineFromGroups($cart->deliveryGroups, $command->lineId);
            }

            return [];
        });
    }

    public function removeLine(RemoveCartLine $command): CartView
    {
        return $this->mutate($command->cart, function (CartRow $cart) use ($command): array {
            $this->lines->remove($cart, $command->lineId);

            return self::pruneLineFromGroups($cart->deliveryGroups, $command->lineId);
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
        // The host's directory owns the customer's groups (docs/architecture/data.md); the command may add groups for
        // this cart. Read before the transaction: the directory is the host's and may be slow.
        $groups = $command->customer === null
            ? $command->groups
            : array_values(array_unique([...$this->customers->find($command->customer)->groups ?? [], ...$command->groups]));

        return $this->mutate($command->cart, static function (CartRow $cart) use ($command, $groups): ?array {
            $sameCustomer = $cart->customer === null || $command->customer === null
                ? $cart->customer === $command->customer
                : $cart->customer->equals($command->customer);

            if ($sameCustomer && $cart->groups === $groups) {
                return null;
            }

            return [
                'customer_ref' => $command->customer === null ? null : (string) $command->customer,
                'customer_groups' => SalesCodec::stringsToJson($groups),
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

    public function setPickupPoint(SetCartPickupPoint $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart) use ($command): ?array {
            $same = $cart->pickupPoint === null || $command->pickupPoint === null
                ? $cart->pickupPoint === $command->pickupPoint
                : SalesCodec::pickupPointToJson($cart->pickupPoint) === SalesCodec::pickupPointToJson($command->pickupPoint);

            if ($same) {
                return null;
            }

            return [
                'pickup_point' => $command->pickupPoint === null ? null : SalesCodec::pickupPointToJson($command->pickupPoint),
            ];
        });
    }

    public function selectDelivery(SelectDelivery $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart) use ($command): ?array {
            $changes = [];

            if ($cart->deliveryMethod !== $command->methodCode) {
                $changes['delivery_method'] = $command->methodCode;
            }

            if ($command->pickupPoint !== null) {
                $encoded = SalesCodec::pickupPointToJson($command->pickupPoint);
                $current = $cart->pickupPoint === null ? null : SalesCodec::pickupPointToJson($cart->pickupPoint);

                if ($encoded !== $current) {
                    $changes['pickup_point'] = $encoded;
                }
            } elseif (! $command->mode->requiresPickupPoint() && $cart->pickupPoint !== null) {
                $changes['pickup_point'] = null;
            }

            return $changes === [] ? null : $changes;
        });
    }

    public function clearDelivery(ClearDelivery $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart): ?array {
            $changes = [];

            if ($cart->deliveryMethod !== null) {
                $changes['delivery_method'] = null;
            }

            if ($cart->pickupPoint !== null) {
                $changes['pickup_point'] = null;
            }

            return $changes === [] ? null : $changes;
        });
    }

    public function selectGroupDelivery(SelectGroupDelivery $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart) use ($command): ?array {
            foreach ($command->group->lineIds as $lineId) {
                if ($cart->line($lineId) === null) {
                    throw new CartLineNotFound($lineId);
                }
            }

            $claimed = array_flip($command->group->lineIds);
            $updated = [];
            $replaced = false;

            foreach ($cart->deliveryGroups as $existing) {
                if ($existing->groupKey === $command->group->groupKey) {
                    $updated[] = $command->group;
                    $replaced = true;

                    continue;
                }

                $remaining = array_values(array_filter(
                    $existing->lineIds,
                    static fn (string $id): bool => ! isset($claimed[$id]),
                ));

                if ($remaining !== []) {
                    $updated[] = $existing->withLineIds($remaining);
                }
            }

            if (! $replaced) {
                $updated[] = $command->group;
            }

            $nextJson = SalesCodec::deliveryGroupsToJson($updated);
            $currentJson = SalesCodec::deliveryGroupsToJson($cart->deliveryGroups);

            return $nextJson === $currentJson ? null : ['delivery_groups' => $nextJson];
        });
    }

    public function clearGroupDelivery(ClearGroupDelivery $command): CartView
    {
        return $this->mutate($command->cart, static function (CartRow $cart) use ($command): ?array {
            $filtered = array_values(array_filter(
                $cart->deliveryGroups,
                static fn (CartDeliveryGroup $group): bool => $group->groupKey !== $command->groupKey,
            ));

            if (count($filtered) === count($cart->deliveryGroups)) {
                return null;
            }

            return ['delivery_groups' => SalesCodec::deliveryGroupsToJson($filtered)];
        });
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
        return $this->quoter->quoteCart($this->rows->get($id->value), $this->now())->quote;
    }

    public function quoteSplitDelivery(CartId $id): SplitDeliveryQuote
    {
        $cart = $this->rows->get($id->value);
        $at = $this->now();
        $quoted = $this->quoter->quoteCart($cart, $at, withDelivery: false);
        $context = $cart->context($at);
        $deliveryTotal = Money::zero($cart->currency);
        $pricedGroups = [];

        /** @var array<string, QuotedLine> $quotedByLine */
        $quotedByLine = [];
        foreach ($quoted->quote->lines as $qLine) {
            $quotedByLine[$qLine->lineId] = $qLine;
        }

        foreach ($cart->deliveryGroups as $group) {
            $groupCartLines = [];
            $refs = [];
            $groupValue = Money::zero($cart->currency);

            foreach ($group->lineIds as $lineId) {
                $cartLine = $cart->line($lineId) ?? throw new CartLineNotFound($lineId);
                $groupCartLines[] = $cartLine;
                $qLine = $quotedByLine[$lineId] ?? null;

                if ($qLine instanceof QuotedLine) {
                    $groupValue = $groupValue->plus($qLine->subtotal);
                    foreach ($qLine->adjustments as $adjustment) {
                        $groupValue = $groupValue->plus($adjustment->amount);
                    }

                    if ($qLine->sellable->requiresShipping) {
                        $refs[] = $qLine->sellable->ref;
                    }
                }
            }

            if ($refs === []) {
                continue;
            }

            $destination = $group->pickupPoint?->toDeliveryAddress()
                ?? $group->shippingAddress
                ?? $cart->pickupPoint?->toDeliveryAddress()
                ?? $cart->shippingAddress
                ?? throw new ShippingAddressRequired;

            $parcel = new Parcel(
                CartQuoter::shippingWeight($groupCartLines, $quoted->snapshots),
                $groupValue->isNegative() ? Money::zero($cart->currency) : $groupValue,
            );

            $options = $this->delivery->options(new DeliveryRequest(
                context: $context,
                destination: $destination,
                parcel: $parcel,
                sellables: $refs,
                pickupPoint: $group->pickupPoint ?? $cart->pickupPoint,
                mode: $group->mode,
            ));

            $matched = array_find(
                $options,
                static fn (DeliveryOption $opt): bool => $opt->methodCode === $group->methodCode,
            );

            if (! $matched instanceof DeliveryOption) {
                throw new QuoteFailed(
                    'delivery',
                    'method_unavailable',
                    "delivery method '{$group->methodCode}' is unavailable for cart group '{$group->groupKey}'",
                );
            }

            $deliveryTotal = $deliveryTotal->plus($matched->price);
            $pricedGroups[] = new PricedDeliveryGroup(
                group: $group,
                option: $matched,
                parcel: $parcel,
                destination: $destination,
            );
        }

        return new SplitDeliveryQuote(
            goodsQuote: $quoted->quote,
            groups: $pricedGroups,
            deliveryTotal: $deliveryTotal,
            grandTotal: $quoted->quote->total->plus($deliveryTotal),
        );
    }

    public function deliveryOptions(CartId $id): array
    {
        $cart = $this->rows->get($id->value);
        $address = $cart->pickupPoint?->toDeliveryAddress() ?? $cart->shippingAddress ?? throw new ShippingAddressRequired;

        if ($cart->lines === []) {
            return [];
        }

        $at = $this->now();
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
            new Parcel(CartQuoter::quotedShippingWeight($quoted->quote->lines), $value->isNegative() ? Money::zero($cart->currency) : $value),
            $refs,
            $cart->pickupPoint,
        ));
    }

    public function groupDeliveryOptions(
        CartId $id,
        array $lineIds,
        ?Address $destination = null,
        ?PickupPointDestination $pickupPoint = null,
    ): array {
        if ($lineIds === []) {
            throw new InvalidCommand('lineIds', 'must contain at least one cart line id', 'sales.invalid_lines');
        }

        $cart = $this->rows->get($id->value);
        $groupLines = [];

        foreach (array_values(array_unique($lineIds)) as $lineId) {
            $groupLines[] = $cart->line($lineId) ?? throw new CartLineNotFound($lineId);
        }

        $effectivePickup = $pickupPoint ?? $cart->pickupPoint;
        $address = $effectivePickup?->toDeliveryAddress()
            ?? $destination
            ?? $cart->shippingAddress
            ?? throw new ShippingAddressRequired;

        $at = $this->now();
        $quoted = $this->quoter->quoteCart($cart, $at, withDelivery: false);

        /** @var array<string, QuotedLine> $quotedByLine */
        $quotedByLine = [];
        foreach ($quoted->quote->lines as $qLine) {
            $quotedByLine[$qLine->lineId] = $qLine;
        }

        $value = Money::zero($cart->currency);
        $refs = [];

        foreach ($groupLines as $cartLine) {
            $qLine = $quotedByLine[$cartLine->id] ?? null;

            if (! $qLine instanceof QuotedLine) {
                continue;
            }

            $value = $value->plus($qLine->subtotal);
            foreach ($qLine->adjustments as $adjustment) {
                $value = $value->plus($adjustment->amount);
            }

            if ($qLine->sellable->requiresShipping) {
                $refs[] = $qLine->sellable->ref;
            }
        }

        if ($refs === []) {
            return [];
        }

        return $this->delivery->options(new DeliveryRequest(
            context: $cart->context($at),
            destination: $address,
            parcel: new Parcel(CartQuoter::shippingWeight($groupLines, $quoted->snapshots), $value->isNegative() ? Money::zero($cart->currency) : $value),
            sellables: $refs,
            pickupPoint: $effectivePickup,
            mode: $effectivePickup?->mode,
        ));
    }

    /**
     * @param  list<CartDeliveryGroup>  $groups
     * @return array<string, string>
     */
    private static function pruneLineFromGroups(array $groups, string $removedLineId): array
    {
        if ($groups === []) {
            return [];
        }

        $next = [];
        $changed = false;

        foreach ($groups as $group) {
            $remaining = array_values(array_filter(
                $group->lineIds,
                static fn (string $id): bool => $id !== $removedLineId,
            ));

            if (count($remaining) !== count($group->lineIds)) {
                $changed = true;
            }

            if ($remaining !== []) {
                $next[] = $group->withLineIds($remaining);
            }
        }

        return $changed ? ['delivery_groups' => SalesCodec::deliveryGroupsToJson($next)] : [];
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

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

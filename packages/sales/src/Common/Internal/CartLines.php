<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Identity\Uuid7;
use Commerz\Ports\Sellables\Contracts\Sellables;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Sales\Carts\Exceptions\CartLineNotFound;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\TooManyCartLines;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The only writer of `cart_lines`: one line per sellable, the limits of `commerz-sales.carts.*`, and a sellable that is
 * purchasable now. A caller reads the sellable before its transaction (`assertPurchasable`) and writes the line under
 * the lock of the cart.
 */
final readonly class CartLines
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private SalesConfig $sales,
        private Sellables $sellables,
    ) {}

    /**
     * @throws SellableNotPurchasable
     * @throws SellableUnavailable
     */
    public function assertPurchasable(CartRow $cart, SellableRef $sellable): void
    {
        $this->assertAllPurchasable($cart->context(new DateTimeImmutable('now', new DateTimeZone('UTC'))), [$sellable]);
    }

    /**
     * The same check for the sellables of a cart that does not exist yet (a draft), in one read.
     *
     * @param  list<SellableRef>  $sellables
     *
     * @throws SellableNotPurchasable
     * @throws SellableUnavailable
     */
    public function assertAllPurchasable(CommerceContext $context, array $sellables): void
    {
        foreach ($this->sellables->resolveMany($sellables, $context) as $snapshot) {
            if (! $snapshot->purchasable) {
                throw new SellableNotPurchasable($snapshot->ref, $snapshot->unavailableReason);
            }
        }
    }

    /**
     * Adds `$quantity` to the line of the sellable or opens one. Needs the cart locked.
     *
     * @throws InvalidCommand sales.invalid_quantity
     * @throws TooManyCartLines
     */
    public function add(CartRow $cart, SellableRef $sellable, int $quantity): void
    {
        $existing = array_find($cart->lines, static fn (CartLineRow $line): bool => $line->sellable->equals($sellable));
        $total = ($existing->quantity ?? 0) + $quantity;
        $this->assertQuantity($total);

        $table = $this->db()->table($this->tables->table('cart_lines'));

        if ($existing !== null) {
            $table->where('id', $existing->id)->update(['quantity' => $total, 'updated_at' => Clock::now()]);

            return;
        }

        if (count($cart->lines) >= $this->sales->maxCartLines) {
            throw new TooManyCartLines($this->sales->maxCartLines);
        }

        $now = Clock::now();
        $table->insert([
            'id' => Uuid7::generate(),
            'cart_id' => $cart->id,
            'sellable_ref' => (string) $sellable,
            'quantity' => $quantity,
            'position' => $cart->lines === [] ? 1 : max(array_map(static fn (CartLineRow $line): int => $line->position, $cart->lines)) + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Sets the quantity of a line; 0 removes it. Needs the cart locked.
     *
     * @return bool whether anything changed
     *
     * @throws CartLineNotFound
     * @throws InvalidCommand sales.invalid_quantity
     */
    public function setQuantity(CartRow $cart, string $lineId, int $quantity): bool
    {
        $line = $cart->line($lineId) ?? throw new CartLineNotFound($lineId);

        if ($quantity === 0) {
            $this->remove($cart, $lineId);

            return true;
        }

        $this->assertQuantity($quantity);

        if ($quantity === $line->quantity) {
            return false;
        }

        $this->db()->table($this->tables->table('cart_lines'))
            ->where('id', $lineId)
            ->update(['quantity' => $quantity, 'updated_at' => Clock::now()]);

        return true;
    }

    /** @throws CartLineNotFound */
    public function remove(CartRow $cart, string $lineId): void
    {
        $cart->line($lineId) ?? throw new CartLineNotFound($lineId);

        $this->db()->table($this->tables->table('cart_lines'))->where('id', $lineId)->delete();
    }

    private function assertQuantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > $this->sales->maxLineQuantity) {
            throw new InvalidCommand('quantity', "must be from 1 to {$this->sales->maxLineQuantity}", 'sales.invalid_quantity');
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

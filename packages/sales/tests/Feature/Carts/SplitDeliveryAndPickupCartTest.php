<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\DeliveryOption;
use Commerz\Ports\Delivery\Data\DeliveryRequest;
use Commerz\Ports\Delivery\Data\PickupPointDestination;
use Commerz\Ports\Delivery\Enums\DeliveryMode;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Data\DeliveryCharge;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartDeliveryGroup;
use Commerz\Sales\Carts\Data\ClearGroupDelivery;
use Commerz\Sales\Carts\Data\CreateCart;
use Commerz\Sales\Carts\Data\RemoveCartLine;
use Commerz\Sales\Carts\Data\SelectDelivery;
use Commerz\Sales\Carts\Data\SelectGroupDelivery;
use Commerz\Sales\Carts\Data\SetCartAddress;
use Commerz\Sales\Carts\Data\SetCartPickupPoint;
use Commerz\Sales\Carts\Enums\AddressRole;
use Commerz\Sales\Carts\Exceptions\CartLineNotFound;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Commerce;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

final readonly class TestCartDeliveryStage implements QuoteStage
{
    public function key(): string
    {
        return 'delivery';
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::Delivery;
    }

    public function position(): StagePosition
    {
        return StagePosition::At;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $sel = $state->input->delivery;
        if ($sel === null) {
            return $state;
        }

        $price = match ($sel->methodCode) {
            'bopis_store' => Money::zero($state->currency()),
            'cdek_locker' => Money::of('150.00', $state->currency()),
            default => Money::of('300.00', $state->currency()),
        };

        return $state->withDelivery(new DeliveryCharge($sel->methodCode, $sel->methodCode, 'cdek', $price));
    }
}

final class TestCartDeliveryPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.cart-delivery-stage', '1.0.0', ['commerz.core']);
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(QuoteStage::KIND, new TestCartDeliveryStage);
    }
}

beforeEach(function (): void {
    config()->set('database.connections.cart_split_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'cart_split_sqlite');
    config()->set('commerz.plugins', [TestCartDeliveryPlugin::class]);
    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(Stores::class);
    app()->forgetInstance(Channels::class);
    app()->forgetInstance(Carts::class);
    app()->forgetInstance(QuotePipeline::class);
    DB::purge('cart_split_sqlite');

    $db = DB::connection('cart_split_sqlite');
    $db->statement('PRAGMA foreign_keys = ON');

    $db->statement(<<<'SQL'
        create table cmz_stores (
            id text primary key,
            code text not null unique,
            name text not null,
            default_currency text not null,
            order_number_prefix text not null default '',
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_channels (
            id text primary key,
            store_id text not null references cmz_stores(id),
            code text not null unique,
            name text not null,
            currencies text not null,
            default_currency text not null,
            locales text not null,
            default_locale text not null,
            prices_include_tax integer not null default 0,
            status text not null default 'active',
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_carts (
            id text primary key,
            kind text not null default 'cart',
            state text not null default 'open',
            channel_id text not null,
            currency text not null,
            locale text not null,
            customer_ref text,
            customer_groups text not null default '[]',
            codes text not null default '[]',
            shipping_address text,
            billing_address text,
            delivery_method text,
            pickup_point text,
            delivery_groups text not null default '[]',
            created_by_ref text,
            version integer not null default 1,
            converted_at text,
            created_at text not null,
            updated_at text not null
        )
    SQL);

    $db->statement(<<<'SQL'
        create table cmz_cart_lines (
            id text primary key,
            cart_id text not null references cmz_carts(id) on delete cascade,
            sellable_ref text not null,
            seller_ref text,
            quantity integer not null,
            position integer not null,
            created_at text not null,
            updated_at text not null,
            unique (cart_id, sellable_ref)
        )
    SQL);

    app()->singleton(Transactions::class, static fn (): Transactions => new class($db) implements Transactions
    {
        public function __construct(private readonly ConnectionInterface $db) {}

        public function run(callable $work, int $lockTimeoutMs = 3000): mixed
        {
            return $this->db->transaction(static fn (): mixed => $work());
        }

        public function inTransaction(): bool
        {
            return $this->db->transactionLevel() > 0;
        }
    });

    app()->singleton(Clock::class, static fn (): Clock => new FrozenClock(new DateTimeImmutable('2026-11-26T12:00:00Z')));

    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        new SellableSnapshot(SellableRef::fromString('sku:fridge'), 'FRIDGE', 'Fridge', SellableKind::Physical, true, basePrice: Money::of('50000.00', 'RUB'), weightGrams: 45_000),
        new SellableSnapshot(SellableRef::fromString('sku:mug'), 'MUG', 'Coffee Mug', SellableKind::Physical, true, basePrice: Money::of('800.00', 'RUB'), weightGrams: 350),
        new SellableSnapshot(SellableRef::fromString('sku:shirt'), 'SHIRT', 'T-Shirt', SellableKind::Physical, true, basePrice: Money::of('1500.00', 'RUB'), weightGrams: 250),
    );

    $this->commerce->delivery->offer(
        new DeliveryOption('courier_freight', 'Freight Courier', Money::of('1200.00', 'RUB'), 'cdek', 3, [], DeliveryMode::Shipping),
        new DeliveryOption('cdek_locker', 'CDEK Locker', Money::of('150.00', 'RUB'), 'cdek', 2, [], DeliveryMode::PickupPoint),
        new DeliveryOption('bopis_store', 'In-Store Pickup', Money::zero('RUB'), 'manual', 0, [], DeliveryMode::LocalPickup),
    );

    $store = app(Stores::class)->create(new CreateStore('main', 'Main Store', Currency::of('RUB')));
    $this->channel = app(Channels::class)->create(new CreateChannel($store->id, 'web', 'Web', [Currency::of('RUB')]))->id;
});

it('keeps PickupPointDestination separate from billing address and quotes PUDO/BOPIS without a street shipping address', function (): void {
    /** @var Carts $carts */
    $carts = app(Carts::class);
    $cart = $carts->create(new CreateCart(CommerceContext::for($this->channel, Currency::of('RUB'), 'ru')));
    $cart = $carts->addLine(new AddCartLine($cart->id, SellableRef::fromString('sku:mug'), 2));

    $billing = new Address('RU', 'RU-MOW', 'Moscow', '119002', ['Arbat 25, apt 8'], 'Olga Ivanova', '+79995554433');
    $locker = new PickupPointDestination(
        pointId: 'cdek-msk-77',
        provider: 'cdek',
        mode: DeliveryMode::PickupPoint,
        address: new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Tverskaya 9, Locker']),
        recipientName: 'Olga Ivanova',
        recipientPhone: '+79995554433',
        name: 'CDEK Locker Tverskaya',
    );

    $cart = $carts->setAddress(new SetCartAddress($cart->id, AddressRole::Billing, $billing));
    $cart = $carts->selectDelivery(new SelectDelivery($cart->id, 'cdek_locker', DeliveryMode::PickupPoint, $locker));

    expect($cart->shippingAddress)->toBeNull()
        ->and($cart->billingAddress)->toEqual($billing)
        ->and($cart->pickupPoint)->toEqual($locker)
        ->and($carts->deliveryOptions($cart->id))->toHaveCount(3);

    $quote = $carts->quote($cart->id);
    // 2 mugs * 800.00 + 150.00 locker delivery = 1750.00 RUB
    expect($quote->total->toDecimal())->toBe('1750.00');

    // Clearing pickup point via SetCartPickupPoint leaves billingAddress intact
    $cleared = $carts->setPickupPoint(new SetCartPickupPoint($cart->id, null));
    expect($cleared->pickupPoint)->toBeNull()
        ->and($cleared->billingAddress)->toEqual($billing);
});

it('supports split delivery method selection per cart line group, disjoint reassignment, line pruning, and split quoting', function (): void {
    /** @var Carts $carts */
    $carts = app(Carts::class);
    $cart = $carts->create(new CreateCart(CommerceContext::for($this->channel, Currency::of('RUB'), 'ru')));
    $cart = $carts->addLine(new AddCartLine($cart->id, SellableRef::fromString('sku:fridge'), 1));
    $cart = $carts->addLine(new AddCartLine($cart->id, SellableRef::fromString('sku:mug'), 2));
    $cart = $carts->addLine(new AddCartLine($cart->id, SellableRef::fromString('sku:shirt'), 1));

    [$fridgeLine, $mugLine, $shirtLine] = $cart->lines;

    $homeAddress = new Address('RU', 'RU-MOW', 'Moscow', '125009', ['Nikitskaya 12'], 'Ivan', '+79991110000');
    $bopisPoint = new PickupPointDestination(
        pointId: 'store-moscow-1',
        provider: 'manual',
        mode: DeliveryMode::LocalPickup,
        address: new Address('RU', 'RU-MOW', 'Moscow', '101000', ['Red Square 3, GUM']),
        recipientName: 'Ivan',
        recipientPhone: '+79991110000',
    );
    $lockerPoint = new PickupPointDestination(
        pointId: 'cdek-locker-5',
        provider: 'cdek',
        mode: DeliveryMode::PickupPoint,
        address: new Address('RU', 'RU-MOW', 'Moscow', '105064', ['Zemlyanoy Val 33']),
    );

    // Group 1: heavy fridge shipped by freight courier to home address
    $cart = $carts->selectGroupDelivery(new SelectGroupDelivery(
        cart: $cart->id,
        groupKey: 'freight-home',
        lineIds: [$fridgeLine->id, $mugLine->id],
        methodCode: 'courier_freight',
        mode: DeliveryMode::Shipping,
        shippingAddress: $homeAddress,
    ));

    // Group 2: reassigns mugLine out of freight-home into locker group, plus shirtLine via BOPIS
    $cart = $carts->selectGroupDelivery(new SelectGroupDelivery(
        cart: $cart->id,
        groupKey: 'pudo-locker',
        lineIds: [$mugLine->id],
        methodCode: 'cdek_locker',
        mode: DeliveryMode::PickupPoint,
        pickupPoint: $lockerPoint,
    ));

    $cart = $carts->selectGroupDelivery(new SelectGroupDelivery(
        cart: $cart->id,
        groupKey: 'store-bopis',
        lineIds: [$shirtLine->id],
        methodCode: 'bopis_store',
        mode: DeliveryMode::LocalPickup,
        pickupPoint: $bopisPoint,
    ));

    expect($cart->deliveryGroups)->toHaveCount(3)
        ->and($cart->deliveryGroups[0]->lineIds)->toBe([$fridgeLine->id]) // mugLine moved cleanly to pudo-locker!
        ->and($cart->deliveryGroups[1]->lineIds)->toBe([$mugLine->id])
        ->and($cart->deliveryGroups[2]->lineIds)->toBe([$shirtLine->id]);

    // Group-level deliveryOptions computes weight for just that group's lines
    $groupOpts = $carts->groupDeliveryOptions($cart->id, [$mugLine->id], pickupPoint: $lockerPoint);
    $requests = $this->commerce->delivery->requests;
    $lastRequest = end($requests);

    if (! $lastRequest instanceof DeliveryRequest) {
        throw new RuntimeException('Expected a delivery request.');
    }

    expect($groupOpts)->not->toBeEmpty()
        ->and($lastRequest->parcel->weightGrams)->toBe(700); // 2 * 350 g

    // Split quote prices goods (50000 + 1600 + 1500 = 53100.00) + deliveries (1200 + 150 + 0 = 1350.00) = 54450.00 RUB
    $splitQuote = $carts->quoteSplitDelivery($cart->id);
    expect($splitQuote->goodsQuote->total->toDecimal())->toBe('53100.00')
        ->and($splitQuote->groups)->toHaveCount(3)
        ->and($splitQuote->deliveryTotal->toDecimal())->toBe('1350.00')
        ->and($splitQuote->grandTotal->toDecimal())->toBe('54450.00');

    // Removing shirtLine automatically prunes the now-empty store-bopis group
    $afterRemove = $carts->removeLine(new RemoveCartLine($cart->id, $shirtLine->id));
    expect(array_map(static fn (CartDeliveryGroup $g): string => $g->groupKey, $afterRemove->deliveryGroups))
        ->toBe(['freight-home', 'pudo-locker']);

    // Clearing pudo-locker group leaves only freight-home
    $afterClear = $carts->clearGroupDelivery(new ClearGroupDelivery($cart->id, 'pudo-locker'));
    expect(array_map(static fn (CartDeliveryGroup $g): string => $g->groupKey, $afterClear->deliveryGroups))
        ->toBe(['freight-home']);

    // Unknown line ID is rejected
    expect(fn () => $carts->selectGroupDelivery(new SelectGroupDelivery(
        cart: $cart->id,
        groupKey: 'bad',
        lineIds: ['nonexistent-line-id'],
        methodCode: 'courier_freight',
        shippingAddress: $homeAddress,
    )))->toThrow(CartLineNotFound::class);
});

<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Sales\Carts\Contracts\Carts;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Carts\Data\CartLineView;
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
use Commerz\Sales\Carts\Exceptions\CartLineNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotFound;
use Commerz\Sales\Carts\Exceptions\CartNotOpen;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Carts\Exceptions\TooManyCartLines;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Helpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(ctSnapshot('sku:a'), ctSnapshot('sku:b', '50.00'), ctSnapshot('sku:c', '10.00'));
    $this->web = ctChannel();
});

it('creates an open empty cart in the channel, currency and locale of the context', function (): void {
    $actor = ActorRef::of('operator', '7');
    $context = ctContext($this->web)->withCustomer(CustomerRef::of('crm', '42'), ['vip', 'wholesale']);

    $cart = ctCarts()->create(new CreateCart($context, $actor));

    expect($cart->kind)->toBe(CartKind::Cart)
        ->and($cart->state)->toBe(CartState::Open)
        ->and($cart->channel)->toEqual($this->web)
        ->and($cart->currency->code)->toBe('RUB')
        ->and($cart->locale)->toBe('ru')
        ->and($cart->customer)->toEqual(CustomerRef::of('crm', '42'))
        ->and($cart->customerGroups)->toBe(['vip', 'wholesale'])
        ->and($cart->createdBy)->toEqual($actor)
        ->and($cart->lines)->toBe([])
        ->and($cart->codes)->toBe([])
        ->and($cart->version)->toBe(1)
        ->and(ctCarts()->find($cart->id))->toEqual($cart);
});

it('refuses a cart in an archived channel or a currency the channel does not sell in', function (): void {
    expect(fn () => ctCarts()->create(new CreateCart(ctContext($this->web, 'EUR'))))->toThrow(CurrencyNotAllowed::class);

    app(Channels::class)->archive(new ArchiveChannel($this->web));

    expect(fn () => ctCarts()->create(new CreateCart(ctContext($this->web))))->toThrow(ChannelArchived::class);
});

it('adds a line, and a second add of the sellable merges into it', function (): void {
    $cart = ctCart($this->web);

    $one = ctAdd($cart, 'sku:a', 2);
    $two = ctAdd($cart, 'sku:b');
    $merged = ctAdd($cart, 'sku:a', 3);

    expect($one->lines)->toHaveCount(1)
        ->and($two->lines)->toHaveCount(2)
        ->and($merged->lines)->toHaveCount(2)
        ->and(array_map(fn (CartLineView $line) => [(string) $line->sellable, $line->quantity], $merged->lines))->toBe([['sku:a', 5], ['sku:b', 1]])
        ->and($merged->lines[0]->id)->toBe($one->lines[0]->id);
});

it('moves the version on with every change and leaves it alone when nothing changes', function (): void {
    $cart = ctCart($this->web);
    $added = ctAdd($cart, 'sku:a', 2);

    $same = ctCarts()->setLineQuantity(new SetCartLineQuantity($cart->id, $added->lines[0]->id, 2));
    $changed = ctCarts()->setLineQuantity(new SetCartLineQuantity($cart->id, $added->lines[0]->id, 3));

    expect($cart->version)->toBe(1)
        ->and($added->version)->toBe(2)
        ->and($same->version)->toBe(2)
        ->and($changed->version)->toBe(3)
        ->and($changed->updatedAt >= $cart->updatedAt)->toBeTrue();
});

it('limits the lines and the quantity of a line by the configuration', function (): void {
    ctConfig(['max_lines' => 2, 'max_quantity' => 5]);
    $cart = ctCart($this->web);
    ctAdd($cart, 'sku:a', 4);
    ctAdd($cart, 'sku:b');

    expect(fn () => ctAdd($cart, 'sku:c'))->toThrow(TooManyCartLines::class)
        ->and(fn () => ctAdd($cart, 'sku:a', 2))->toThrow(InvalidCommand::class, 'from 1 to 5')
        ->and(fn () => ctCarts()->setLineQuantity(new SetCartLineQuantity($cart->id, ctCarts()->find($cart->id)->lines[0]->id, 6)))->toThrow(InvalidCommand::class)
        ->and(ctAdd($cart, 'sku:a')->lines[0]->quantity)->toBe(5)
        ->and(fn () => new AddCartLine($cart->id, SellableRef::fromString('sku:a'), 0))->toThrow(InvalidCommand::class)
        ->and(ctCarts()->find($cart->id)->version)->toBe(4);
});

it('refuses a sellable that is not purchasable and one that is unknown, and keeps the cart as it was', function (): void {
    $this->commerce->sellables->add(ctSnapshot('sku:gone', purchasable: false, reason: 'out of range'));
    $cart = ctCart($this->web);

    try {
        ctAdd($cart, 'sku:gone');
    } catch (SellableNotPurchasable $e) {
        expect($e->code())->toBe('sales.sellable_not_purchasable')
            ->and($e->context())->toBe(['sellable' => 'sku:gone', 'reason' => 'out of range']);
    }

    expect(fn () => ctAdd($cart, 'sku:gone'))->toThrow(SellableNotPurchasable::class)
        ->and(fn () => ctAdd($cart, 'sku:nobody'))->toThrow(SellableUnavailable::class)
        ->and(ctCarts()->find($cart->id)->lines)->toBe([])
        ->and(ctCarts()->find($cart->id)->version)->toBe(1);
});

it('removes a line by quantity 0 or by command, and fails on an unknown line', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a');
    $cart = ctAdd($cart, 'sku:b');
    [$a, $b] = $cart->lines;

    $zero = ctCarts()->setLineQuantity(new SetCartLineQuantity($cart->id, $a->id, 0));
    $none = ctCarts()->removeLine(new RemoveCartLine($cart->id, $b->id));

    expect($zero->lines)->toHaveCount(1)
        ->and($none->lines)->toBe([])
        ->and(fn () => ctCarts()->removeLine(new RemoveCartLine($cart->id, $b->id)))->toThrow(CartLineNotFound::class)
        ->and(fn () => ctCarts()->setLineQuantity(new SetCartLineQuantity($cart->id, $a->id, 1)))->toThrow(CartLineNotFound::class)
        ->and(fn () => new SetCartLineQuantity($cart->id, $a->id, -1))->toThrow(InvalidCommand::class);
});

it('keeps the position of the lines in the order they were added', function (): void {
    $cart = ctAdd(ctAdd(ctAdd(ctCart($this->web), 'sku:c'), 'sku:a'), 'sku:b');
    ctCarts()->removeLine(new RemoveCartLine($cart->id, $cart->lines[0]->id));

    $after = ctAdd($cart, 'sku:c');

    expect(array_map(fn (CartLineView $line) => (string) $line->sellable, $after->lines))->toBe(['sku:a', 'sku:b', 'sku:c']);
});

it('keeps trimmed codes without repeats', function (): void {
    $cart = ctCart($this->web);

    ctCarts()->enterCode(new EnterCode($cart->id, ' AUTUMN '));
    $second = ctCarts()->enterCode(new EnterCode($cart->id, 'VIP'));
    $again = ctCarts()->enterCode(new EnterCode($cart->id, 'AUTUMN'));
    $removed = ctCarts()->removeCode(new RemoveCode($cart->id, 'AUTUMN'));
    $absent = ctCarts()->removeCode(new RemoveCode($cart->id, 'NOPE'));

    expect($second->codes)->toBe(['AUTUMN', 'VIP'])
        ->and($again->version)->toBe($second->version)
        ->and($removed->codes)->toBe(['VIP'])
        ->and($absent->version)->toBe($removed->version)
        ->and(fn () => new EnterCode($cart->id, '  '))->toThrow(InvalidCommand::class)
        ->and(fn () => new EnterCode($cart->id, str_repeat('x', 65)))->toThrow(InvalidCommand::class);
});

it('takes the customer groups from the host directory and adds those of the command', function (): void {
    // docs/architecture/data.md: the CustomerDirectory owns the groups that drive prices and promotions.
    app()->instance(CustomerDirectory::class, new class implements CustomerDirectory
    {
        public function find(CustomerRef $customer): ?CustomerProfile
        {
            return (string) $customer === 'crm:acme' ? new CustomerProfile($customer, ['b2b', 'vip']) : null;
        }
    });
    app()->forgetInstance(Carts::class);
    $cart = ctCart($this->web);

    $known = ctCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', 'acme')));
    $added = ctCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', 'acme'), ['vip', 'deal-7']));
    $unknown = ctCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', 'nobody'), ['retail']));
    $guest = ctCarts()->setCustomer(new SetCartCustomer($cart->id, null));

    expect($known->customerGroups)->toBe(['b2b', 'vip'])
        ->and($added->customerGroups)->toBe(['b2b', 'vip', 'deal-7'])
        ->and($unknown->customerGroups)->toBe(['retail'])
        ->and($guest->customerGroups)->toBe([])
        ->and(ctCarts()->quote($cart->id)->context->customerGroups)->toBe([]);
});

it('sets and clears the customer, the addresses and the delivery method', function (): void {
    $cart = ctCart($this->web);
    $customer = CustomerRef::of('crm', '9');

    $withCustomer = ctCarts()->setCustomer(new SetCartCustomer($cart->id, $customer, ['vip', 'vip']));
    $unchanged = ctCarts()->setCustomer(new SetCartCustomer($cart->id, $customer, ['vip']));
    $withShipping = ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, ctAddress()));
    $withBilling = ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Billing, ctAddress('US', 'US-NY')));
    $unchangedAddress = ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Billing, ctAddress('US', 'US-NY')));
    $selected = ctCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier'));
    $cleared = ctCarts()->clearDelivery(new ClearDelivery($cart->id));
    $guest = ctCarts()->setCustomer(new SetCartCustomer($cart->id, null));
    $noShipping = ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, null));

    expect($withCustomer->customer)->toEqual($customer)
        ->and($withCustomer->customerGroups)->toBe(['vip'])
        ->and($unchanged->version)->toBe($withCustomer->version)
        ->and($withShipping->shippingAddress)->toEqual(ctAddress())
        ->and($withBilling->billingAddress)->toEqual(ctAddress('US', 'US-NY'))
        ->and($unchangedAddress->version)->toBe($withBilling->version)
        ->and($selected->deliveryMethod)->toBe('courier')
        ->and($cleared->deliveryMethod)->toBeNull()
        ->and($guest->customer)->toBeNull()
        ->and($guest->customerGroups)->toBe([])
        ->and($noShipping->shippingAddress)->toBeNull()
        ->and($noShipping->billingAddress)->toEqual(ctAddress('US', 'US-NY'))
        ->and(fn () => new SetCartCustomer($cart->id, $customer, ['Bad Group']))->toThrow(InvalidCommand::class)
        ->and(fn () => new SelectDelivery($cart->id, ''))->toThrow(InvalidCommand::class);
});

it('never changes the channel or the currency of a cart', function (): void {
    $cart = ctAdd(ctCart($this->web, 'USD'), 'sku:a');
    ctCarts()->setCustomer(new SetCartCustomer($cart->id, CustomerRef::of('crm', '1')));
    ctCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier'));

    $after = ctCarts()->find($cart->id);

    expect($after->channel)->toEqual($this->web)
        ->and($after->currency->code)->toBe('USD')
        ->and($after->locale)->toBe('ru');
});

it('refuses every change of a converted cart', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a');
    ctConvert($cart);
    $line = $cart->lines[0]->id;

    $changes = [
        fn () => ctAdd($cart, 'sku:b'),
        fn () => ctCarts()->setLineQuantity(new SetCartLineQuantity($cart->id, $line, 2)),
        fn () => ctCarts()->removeLine(new RemoveCartLine($cart->id, $line)),
        fn () => ctCarts()->enterCode(new EnterCode($cart->id, 'X')),
        fn () => ctCarts()->removeCode(new RemoveCode($cart->id, 'X')),
        fn () => ctCarts()->setCustomer(new SetCartCustomer($cart->id, null)),
        fn () => ctCarts()->setAddress(new SetCartAddress($cart->id, AddressRole::Shipping, null)),
        fn () => ctCarts()->selectDelivery(new SelectDelivery($cart->id, 'courier')),
        fn () => ctCarts()->clearDelivery(new ClearDelivery($cart->id)),
    ];

    foreach ($changes as $change) {
        expect($change)->toThrow(CartNotOpen::class);
    }

    expect(ctCarts()->find($cart->id)->state)->toBe(CartState::Converted)
        ->and(ctCarts()->find($cart->id)->version)->toBe($cart->version);
});

it('fails on an unknown cart', function (): void {
    $unknown = CartId::generate();

    expect(fn () => ctCarts()->find($unknown))->toThrow(CartNotFound::class)
        ->and(fn () => ctAdd($unknown, 'sku:a'))->toThrow(CartNotFound::class)
        ->and(fn () => ctCarts()->enterCode(new EnterCode($unknown, 'X')))->toThrow(CartNotFound::class)
        ->and(fn () => ctCarts()->quote($unknown))->toThrow(CartNotFound::class)
        ->and(fn () => ctCarts()->deliveryOptions($unknown))->toThrow(CartNotFound::class);
});

it('lists carts newest first by keyset, filtered by kind, state and customer', function (): void {
    $customer = CustomerRef::of('crm', '5');
    $carts = [];

    foreach (range(1, 5) as $i) {
        $carts[] = ctCart($this->web);
    }

    ctCarts()->setCustomer(new SetCartCustomer($carts[1]->id, $customer));
    ctCarts()->setCustomer(new SetCartCustomer($carts[3]->id, $customer));
    DB::table(app(TableNames::class)->table('carts'))->where('id', $carts[2]->id->value)->update(['kind' => 'draft']);
    ctConvert($carts[4]);

    $first = ctCarts()->list(new ListCarts(limit: 2));
    $second = ctCarts()->list(new ListCarts(after: $first->next, limit: 2));
    $third = ctCarts()->list(new ListCarts(after: $second->next, limit: 2));
    $ids = static function (Page $page): array {
        $values = [];

        foreach ($page->items as $cart) {
            if (! $cart instanceof CartView) {
                throw new RuntimeException('Expected a cart.');
            }

            $values[] = $cart->id->value;
        }

        return $values;
    };

    expect($ids($first))->toBe([$carts[4]->id->value, $carts[3]->id->value])
        ->and($ids($second))->toBe([$carts[2]->id->value, $carts[1]->id->value])
        ->and($ids($third))->toBe([$carts[0]->id->value])
        ->and($third->next)->toBeNull()
        ->and($ids(ctCarts()->list(new ListCarts(kind: CartKind::Draft))))->toBe([$carts[2]->id->value])
        ->and($ids(ctCarts()->list(new ListCarts(state: CartState::Converted))))->toBe([$carts[4]->id->value])
        ->and($ids(ctCarts()->list(new ListCarts(customer: $customer))))->toBe([$carts[3]->id->value, $carts[1]->id->value])
        ->and(fn () => ctCarts()->list(new ListCarts(after: Cursor::after('garbage'))))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListCarts(limit: 0))->toThrow(InvalidCommand::class);
});

it('carries the lines in the listed carts', function (): void {
    $cart = ctAdd(ctCart($this->web), 'sku:a', 3);

    $listed = ctCarts()->list(new ListCarts)->items[0];

    expect($listed->lines[0]->quantity)->toBe(3)
        ->and($listed->id->equals($cart->id))->toBeTrue();
});

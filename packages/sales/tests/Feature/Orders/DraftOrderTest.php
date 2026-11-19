<?php

declare(strict_types=1);

use Commerz\Identity\ActorRef;
use Commerz\Identity\SellableRef;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Sales\Carts\Data\AddCartLine;
use Commerz\Sales\Carts\Data\CartLineView;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Carts\Enums\CartState;
use Commerz\Sales\Carts\Exceptions\SellableNotPurchasable;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Orders\Data\DraftLine;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Testkit\Commerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Helpers.php';

uses(RefreshDatabase::class, SlsEnvironment::class);

beforeEach(function (): void {
    $this->commerce = Commerce::fake();
    $this->commerce->sellables->add(
        slsSnapshot('fake:a', '100.00'),
        slsSnapshot('crm:consult', '500.00', SellableKind::Service),
        slsSnapshot('fake:off', purchasable: false),
    );
    $this->web = slsChannel();
});

function drfLine(string $ref, int $quantity = 1): DraftLine
{
    return new DraftLine(SellableRef::fromString($ref), $quantity);
}

it('opens a draft as a cart of kind draft, adding up lines of one sellable', function (): void {
    $manager = ActorRef::of('user', '5');

    $draft = slsOrders()->draft(new DraftOrder(
        slsContext($this->web),
        [drfLine('crm:consult'), drfLine('fake:a', 2), drfLine('crm:consult', 2)],
        actor: $manager,
        codes: [' promo:p1 ', 'promo:p1'],
        shippingAddress: slsAddress(),
        deliveryMethod: 'flat:300.00',
    ));

    expect([$draft->kind, $draft->state, $draft->version, $draft->createdBy])->toEqual([CartKind::Draft, CartState::Open, 1, $manager])
        ->and(array_map(static fn (CartLineView $line): array => [(string) $line->sellable, $line->quantity], $draft->lines))
        ->toBe([['crm:consult', 3], ['fake:a', 2]])
        ->and($draft->codes)->toBe(['promo:p1'])
        ->and($draft->shippingAddress)->toEqual(slsAddress())
        ->and($draft->billingAddress)->toBeNull()
        ->and($draft->deliveryMethod)->toBe('flat:300.00')
        ->and(slsCarts()->find($draft->id))->toEqual($draft);
});

it('is edited with the cart commands and placed as an order of origin draft without a payment deadline', function (): void {
    $draft = slsOrders()->draft(new DraftOrder(slsContext($this->web), [drfLine('crm:consult')]));
    slsCarts()->addLine(new AddCartLine($draft->id, SellableRef::fromString('crm:consult'), 1));

    $order = slsCheckout()->place(new PlaceOrder($draft->id, slsCarts()->quote($draft->id)->total, actor: ActorRef::of('user', '5')));

    expect($order->origin)->toBe(CartKind::Draft)
        ->and($order->paymentDueAt)->toBeNull()
        ->and($order->lines[0]->quantity)->toBe(2)
        ->and($order->total->toDecimal())->toBe('1000.00')
        ->and(slsOrders()->history($order->id)[0]->actor)->toEqual(ActorRef::of('user', '5'));
});

it('writes nothing for an unpurchasable line or an archived channel', function (): void {
    $carts = fn (): int => DB::table(app(TableNames::class)->table('carts'))->count();

    expect(fn () => slsOrders()->draft(new DraftOrder(slsContext($this->web), [drfLine('fake:a'), drfLine('fake:off')])))
        ->toThrow(SellableNotPurchasable::class)
        ->and($carts())->toBe(0);

    app(Channels::class)->archive(new ArchiveChannel($this->web));

    expect(fn () => slsOrders()->draft(new DraftOrder(slsContext($this->web), [drfLine('fake:a')])))
        ->toThrow(ChannelArchived::class)
        ->and($carts())->toBe(0);
});

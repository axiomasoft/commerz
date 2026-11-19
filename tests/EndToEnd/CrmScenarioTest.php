<?php

declare(strict_types=1);

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\CommerzServiceProvider;
use Commerz\Context\CommerceContext;
use Commerz\EndToEnd\Fixtures\CrmCustomers;
use Commerz\EndToEnd\Fixtures\CrmPlugin;
use Commerz\EndToEnd\Fixtures\CrmServices;
use Commerz\EndToEnd\Support\CommittedDatabase;
use Commerz\EndToEnd\Support\PortMatrix;
use Commerz\Facades\Commerz;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Delivery\Contracts\DeliveryOptions;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Prices\Contracts\PriceResolver;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\Prices\Data\SetPrice;
use Commerz\Pricing\PricingServiceProvider;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Sales\Carts\Data\SetCartCustomer;
use Commerz\Sales\Carts\Enums\CartKind;
use Commerz\Sales\Checkout\Data\PlaceOrder;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Fulfillments\Data\CreateFulfillment;
use Commerz\Sales\Orders\Data\DraftLine;
use Commerz\Sales\Orders\Data\DraftOrder;
use Commerz\Sales\Orders\Enums\FulfillmentState;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Orders\Enums\PaymentState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\RecordPaymentEvent;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Schema\TableNames;
use Commerz\Shipping\ShippingServiceProvider;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// B3 for a CRM: the host installs the core, pricing (for taxes and its B2B prices) and sales — no catalog, inventory,
// shipping or promotions — sells its own services through a sellable provider of its own and names its customers
// through its own directory. Payments refuse to run inside a transaction, so the data is committed.

trait CrmEnvironment
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PricingServiceProvider::class, SalesServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.stock', 'unlimited');
        $app['config']->set('commerz.plugins', [CrmPlugin::class]);
        $app['config']->set('commerz.customers', CrmCustomers::class);
        // Services have no delivery address: the CRM taxes them in its home zone.
        $app['config']->set('commerz-pricing.tax.default_zone', 'ru');
    }
}

uses(CrmEnvironment::class);

// The schema of another provider set may still be there: the subset starts from its own migrations only.
beforeEach(function (): void {
    $this->artisan('migrate:fresh')->assertSuccessful();
});

afterEach(fn () => CommittedDatabase::truncate());

it('sells the services of a CRM from draft to completed without the shop packages', function (): void {
    $rub = Currency::of('RUB');
    $store = Commerz::stores()->create(new CreateStore('crm', 'CRM', $rub, 'CRM-'));
    $channel = Commerz::channels()->create(new CreateChannel($store->id, 'crm', 'CRM', [$rub], ['ru']))->id;

    // Net B2B prices: the audit has a contract price for the `b2b` group; consulting has none and falls back to the
    // CRM's own tariff (`fallback_to_sellable`), so the CRM never has to copy its tariffs into pricing.
    $contract = Pricing::priceLists()->create(new CreatePriceList('contract', 'Contract', $rub, [$channel]));
    Pricing::prices()->set(new SetPrice($contract->id, CrmServices::ref('audit'), Money::of('3600.00', 'RUB'), customerGroup: 'b2b'));
    Pricing::taxes()->defineClass(new DefineTaxClass('standard', 'Standard'));
    Pricing::taxes()->defineZone(new DefineTaxZone('ru', 'Russia', [new TaxZoneMember('RU')]));
    Pricing::taxes()->setRate(new SetTaxRate('standard', 'ru', 2000, 'vat'));

    $manager = ActorRef::of('user', 'manager-5');
    $draft = Sales::orders()->draft(new DraftOrder(
        CommerceContext::for($channel, $rub, 'ru'),
        [new DraftLine(CrmServices::ref('consulting'), 2), new DraftLine(CrmServices::ref('audit'), 1)],
        actor: $manager,
    ));
    // The CRM names only the account: its `b2b` group comes from the CRM's own directory (`commerz.customers`).
    $customer = Sales::carts()->setCustomer(new SetCartCustomer($draft->id, CustomerRef::fromString(CrmCustomers::ACME)));

    // consulting 2 × 1500.00 (tariff) = 3000.00, audit 1 × 3600.00 (contract) = 3600.00; net 6600.00.
    // VAT 20% on top, per line: 600.00 + 720.00 = 1320.00; no delivery; total 7920.00.
    $quote = Sales::carts()->quote($draft->id);
    [$consulting, $audit] = $quote->lines;

    expect([$consulting->unitPrice, $consulting->priceSource])->toEqual([Money::of('1500.00', 'RUB'), 'sellable'])
        ->and([$audit->unitPrice, $audit->priceSource])->toEqual([Money::of('3600.00', 'RUB'), 'price_list:contract'])
        ->and(array_map(static fn ($tax): array => [$tax->lineId, $tax->amount->toDecimal(), $tax->included], $quote->taxLines))
        ->toBe([[$consulting->lineId, '600.00', false], [$audit->lineId, '720.00', false]])
        ->and($quote->delivery)->toBeNull()
        ->and($quote->totals->taxTotal)->toEqual(Money::of('1320.00', 'RUB'))
        ->and($quote->total)->toEqual(Money::of('7920.00', 'RUB'));

    // No shipping address and no delivery method: services ship nothing, so `DeliveryRequired` never arises.
    $order = Sales::checkout()->place(new PlaceOrder($draft->id, $quote->total, actor: $manager));

    expect($customer->customerGroups)->toBe(['b2b'])
        ->and($draft->kind)->toBe(CartKind::Draft)
        ->and($order->origin)->toBe(CartKind::Draft)
        ->and($order->number)->toStartWith('CRM-')
        ->and([$order->state, $order->paymentState, $order->fulfillmentState])
        ->toBe([OrderState::Placed, PaymentState::Unpaid, FulfillmentState::Unfulfilled])
        ->and([(string) $order->customer, $order->customerGroups, $order->customerEmail, $order->customerName])
        ->toBe([CrmCustomers::ACME, ['b2b'], 'billing@acme.test', 'ACME LLC'])
        ->and($order->total)->toEqual($quote->total)
        ->and($order->delivery)->toBeNull();

    $payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
    Sales::payments()->recordEvent(new RecordPaymentEvent('manual', 'crm-bank-1', PaymentEventType::Captured, paymentReference: $payment->reference));

    expect(Sales::orders()->find($order->id)->paymentState)->toBe(PaymentState::Paid);

    // Rendering the services fulfils every line; unlimited stock has nothing to consume.
    Sales::fulfillments()->create(new CreateFulfillment($order->id, array_map(
        static fn ($line): LineQuantity => new LineQuantity($line->id, $line->quantity),
        $order->lines,
    )));
    $done = Sales::orders()->find($order->id);

    expect([$done->state, $done->paymentState, $done->fulfillmentState])
        ->toBe([OrderState::Completed, PaymentState::Paid, FulfillmentState::Fulfilled]);
});

it('keeps the shop packages out of the CRM installation', function (): void {
    $tables = app(TableNames::class);
    $names = array_map(static fn (array $table): string => $table['name'], Schema::getTables());
    $absent = [CatalogServiceProvider::NOUNS, InventoryServiceProvider::NOUNS, ShippingServiceProvider::NOUNS, PromotionsServiceProvider::NOUNS];
    $foreign = array_values(array_filter($names, static function (string $name) use ($tables, $absent): bool {
        // A table is named after its noun, singular or plural (`category_products`, `categories`), as SchemaOwnership reads it.
        foreach (array_merge(...$absent) as $noun) {
            if (str_starts_with($name, $tables->prefix().$noun) || str_starts_with($name, $tables->prefix().Str::plural($noun))) {
                return true;
            }
        }

        return false;
    }));

    // The listing names tables as the prefix check expects: the installed packages' tables are found by it.
    expect($names)->toContain($tables->table('orders'), $tables->table('price_lists'))
        ->and($foreign)->toBe([])
        ->and(Schema::hasTable($tables->prefix().'stock_items'))->toBeFalse()
        ->and(Schema::hasTable($tables->prefix().'products'))->toBeFalse()
        ->and(PortMatrix::plugins())->toBe(['commerz.core', 'commerz.pricing', 'commerz.sales', CrmPlugin::ID])
        ->and(PortMatrix::sellableTypes())->toBe([CrmServices::KEY])
        // The ports of the absent packages stay on the core's defaults; prices come from pricing.
        ->and(array_map(static fn (string $port): string => get_class(app($port)), [
            StockReservations::class, PromotionUsage::class, DeliveryOptions::class, ShipmentBooking::class, PriceResolver::class,
        ]))->toBe([
            'Commerz\Ports\Stock\Internal\UnlimitedStock',
            'Commerz\Ports\Promotions\Internal\NullPromotionUsage',
            'Commerz\Ports\Delivery\Internal\NoDelivery',
            'Commerz\Ports\Delivery\Internal\NoDelivery',
            PortMatrix::IMPLEMENTATIONS[PriceResolver::class],
        ]);

    $this->artisan('packman:doctor')->assertSuccessful();
});

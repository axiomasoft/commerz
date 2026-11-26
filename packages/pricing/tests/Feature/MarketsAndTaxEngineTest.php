<?php

declare(strict_types=1);

use Commerz\Context\CommerceContext;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Taxes\Contracts\TaxEngine;
use Commerz\Ports\Taxes\Data\CalculateTax;
use Commerz\Ports\Taxes\Data\TaxableLine;
use Commerz\Ports\Taxes\Data\TaxExemption;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Facades\Pricing;
use Commerz\Pricing\Markets\Data\ArchiveMarket;
use Commerz\Pricing\Markets\Data\CreateMarket;
use Commerz\Pricing\Markets\Data\ListMarkets;
use Commerz\Pricing\Markets\Data\RestoreMarket;
use Commerz\Pricing\Markets\Data\SetMarketCountries;
use Commerz\Pricing\Markets\Data\UpdateMarket;
use Commerz\Pricing\Markets\Enums\MarketStatus;
use Commerz\Pricing\Markets\Events\MarketChanged;
use Commerz\Pricing\Markets\Exceptions\MarketArchived;
use Commerz\Pricing\Markets\Exceptions\MarketCountryTaken;
use Commerz\Pricing\PriceLists\Data\CreatePriceList;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Internal\PricingTaxEngine;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Testkit\Fakes\FakeCustomers;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Support/SqlitePricingSchema.php';

beforeEach(function (): void {
    bootSqlitePricingSchema('markets_sqlite');
});

it('manages Market aggregates linked to channels and enforces country uniqueness per channel', function (): void {
    Event::fake([MarketChanged::class]);

    $store = app(Stores::class)->create(new CreateStore('main', 'Main Store', Currency::of('EUR')));
    $web = app(Channels::class)->create(new CreateChannel(
        $store->id,
        'web',
        'Web',
        [Currency::of('EUR'), Currency::of('SEK')],
        defaultCurrency: Currency::of('EUR'),
    ));
    $b2b = app(Channels::class)->create(new CreateChannel(
        $store->id,
        'b2b',
        'B2B Wholesale',
        [Currency::of('EUR')],
        defaultCurrency: Currency::of('EUR'),
    ));

    $euList = Pricing::priceLists()->create(new CreatePriceList('eu-retail', 'EU Retail', Currency::of('EUR'), [$web->id]));

    $euMarket = Pricing::markets()->create(new CreateMarket(
        code: 'eu-west',
        name: 'EU West',
        channel: $web->id,
        countries: ['fr', 'de', 'DE'],
        defaultCurrency: Currency::of('EUR'),
        allowedCurrencies: [Currency::of('EUR'), Currency::of('SEK')],
        taxIncluded: true,
        defaultPriceListId: $euList->id,
    ));

    expect($euMarket->countries)->toBe(['DE', 'FR'])
        ->and($euMarket->taxIncluded)->toBeTrue()
        ->and($euMarket->defaultPriceListId)->toEqual($euList->id)
        ->and($euMarket->allowsCurrency(Currency::of('SEK')))->toBeTrue()
        ->and($euMarket->servesCountry('de'))->toBeTrue()
        ->and(Pricing::markets()->resolveForCountry($web->id, 'FR')?->id)->toEqual($euMarket->id)
        ->and(Pricing::markets()->findByCode('eu-west')->id)->toEqual($euMarket->id);

    // Duplicate code rejected
    expect(fn () => Pricing::markets()->create(new CreateMarket('eu-west', 'Dup', $web->id, ['IT'], Currency::of('EUR'))))
        ->toThrow(DuplicateCode::class);

    // Country already taken in the same channel rejected
    expect(fn () => Pricing::markets()->create(new CreateMarket('dach', 'DACH', $web->id, ['AT', 'DE'], Currency::of('EUR'))))
        ->toThrow(MarketCountryTaken::class);

    // Same country in a DIFFERENT channel is allowed
    $b2bMarket = Pricing::markets()->create(new CreateMarket('eu-b2b', 'EU B2B', $b2b->id, ['DE'], Currency::of('EUR'), taxIncluded: false));
    expect(Pricing::markets()->resolveForCountry($b2b->id, 'DE')?->id)->toEqual($b2bMarket->id);

    // Update countries, currencies, and archive/restore lifecycle
    $updated = Pricing::markets()->setCountries(new SetMarketCountries($euMarket->id, ['DE', 'NL', 'BE']));
    expect($updated->countries)->toBe(['BE', 'DE', 'NL'])
        ->and(Pricing::markets()->resolveForCountry($web->id, 'FR'))->toBeNull();

    $updated = Pricing::markets()->update(new UpdateMarket($euMarket->id, name: 'Benelux + DE', clearDefaultPriceList: true));
    expect($updated->name)->toBe('Benelux + DE')
        ->and($updated->defaultPriceListId)->toBeNull();

    $archived = Pricing::markets()->archive(new ArchiveMarket($euMarket->id));
    expect($archived->status)->toBe(MarketStatus::Archived)
        ->and(Pricing::markets()->resolveForCountry($web->id, 'DE'))->toBeNull()
        ->and(fn () => Pricing::markets()->update(new UpdateMarket($euMarket->id, name: 'Blocked')))->toThrow(MarketArchived::class);

    $restored = Pricing::markets()->restore(new RestoreMarket($euMarket->id));
    expect($restored->status)->toBe(MarketStatus::Active)
        ->and(Pricing::markets()->list(new ListMarkets(channel: $web->id, country: 'NL'))->items)->toHaveCount(1);

    Event::assertDispatched(MarketChanged::class);
});

it('implements the core TaxEngine port with B2B VAT Reverse Charge and tax exemptions', function (): void {
    $engine = app(TaxEngine::class);
    expect($engine)->toBeInstanceOf(PricingTaxEngine::class);

    Pricing::taxes()->defineClass(new DefineTaxClass('standard', 'Standard VAT'));
    Pricing::taxes()->defineZone(new DefineTaxZone('de', 'Germany', [new TaxZoneMember('DE')]));
    Pricing::taxes()->defineZone(new DefineTaxZone('fr', 'France', [new TaxZoneMember('FR')]));
    Pricing::taxes()->setRate(new SetTaxRate('standard', 'de', 1900));
    Pricing::taxes()->setRate(new SetTaxRate('standard', 'fr', 2000));

    $ctx = CommerceContext::for(
        ChannelId::generate(),
        Currency::of('EUR'),
        at: new DateTimeImmutable('2026-11-26T12:00:00Z'),
    );
    $lines = [
        TaxableLine::line('l1', Money::of('100.00', 'EUR')),
        TaxableLine::delivery(Money::of('20.00', 'EUR')),
    ];

    // 1. Standard B2C sale to FR -> 20% VAT (20.00 + 4.00 = 24.00 EUR)
    $b2c = $engine->calculate(new CalculateTax($ctx, new Address('FR'), $lines));
    expect($b2c->reverseCharge)->toBeFalse()
        ->and($b2c->exempt)->toBeFalse()
        ->and($b2c->total->toDecimal())->toBe('24.00')
        ->and($b2c->excludedTotal->toDecimal())->toBe('24.00');

    // 2. Cross-border EU B2B (seller DE -> buyer FR with valid FR VAT ID) -> Reverse Charge (0% VAT)
    $b2bCrossBorder = $engine->calculate(new CalculateTax(
        context: $ctx,
        destination: new Address('FR'),
        lines: $lines,
        buyerVatNumber: 'FR 123 456 789 01',
        sellerCountry: 'DE',
    ));
    expect($b2bCrossBorder->reverseCharge)->toBeTrue()
        ->and($b2bCrossBorder->total->toDecimal())->toBe('0.00')
        ->and($b2bCrossBorder->lines)->toHaveCount(2)
        ->and($b2bCrossBorder->lines[0]->code)->toBe('reverse_charge')
        ->and($b2bCrossBorder->lines[0]->rate->basisPoints)->toBe(0);

    // 3. Domestic B2B (seller DE -> buyer DE with DE VAT ID) -> Normal 19% German VAT (19.00 + 3.80 = 22.80 EUR)
    $b2bDomestic = $engine->calculate(new CalculateTax(
        context: $ctx,
        destination: new Address('DE'),
        lines: $lines,
        buyerVatNumber: 'DE123456789',
        sellerCountry: 'DE',
    ));
    expect($b2bDomestic->reverseCharge)->toBeFalse()
        ->and($b2bDomestic->total->toDecimal())->toBe('22.80');

    // 4. Explicit tax exemption & CustomerDirectory tax-exempt profile
    $exempt = $engine->calculate(new CalculateTax(
        context: $ctx,
        destination: new Address('DE'),
        lines: $lines,
        exemption: TaxExemption::exempt('non_profit', 'CERT-2026'),
    ));
    expect($exempt->exempt)->toBeTrue()
        ->and($exempt->total->toDecimal())->toBe('0.00')
        ->and($exempt->lines)->toBe([]);

    $customers = new FakeCustomers;
    $buyer = CustomerRef::of('org', '42');
    $customers->add(new CustomerProfile($buyer, taxExempt: true));
    app()->instance(CustomerDirectory::class, $customers);

    $customerExempt = $engine->calculate(new CalculateTax($ctx->withCustomer($buyer), new Address('DE'), $lines));
    expect($customerExempt->exempt)->toBeTrue()
        ->and($customerExempt->total->toDecimal())->toBe('0.00');
});

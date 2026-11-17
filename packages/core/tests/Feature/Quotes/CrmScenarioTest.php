<?php

declare(strict_types=1);

namespace Commerz\Tests\Feature\Quotes;

use Commerz\Context\CommerceContext;
use Commerz\Facades\Commerz;
use Commerz\Identity\SellableRef;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;
use Commerz\Ports\Sellables\Exceptions\SellableUnavailable;
use Commerz\Ports\Stock\Contracts\StockAvailability;
use Commerz\Ports\Stock\Contracts\StockReservations;
use Commerz\Ports\Stock\Data\AvailabilityLine;
use Commerz\Ports\Stock\Data\CheckAvailability;
use Commerz\Ports\Stock\Data\ReserveLine;
use Commerz\Ports\Stock\Data\ReserveStock;
use Commerz\Quotes\Data\QuotedLine;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteLineInput;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\CreateStore;
use Commerz\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;
use Packman\Plugins\Laravel\PluginRegistrar;
use PHPUnit\Framework\Attributes\Test;

/** A host CRM's services, priced from its own tariffs: the scenario of api-blueprint §9. */
final readonly class CrmServiceSellables implements SellableProvider
{
    /** @var array<string, array{string, string}> id => [name, tariff] */
    private const array SERVICES = ['77' => ['Монтаж', '1500.00'], '78' => ['Выезд', '700.00']];

    public function key(): string
    {
        return 'crm.service';
    }

    public function resolve(array $refs, CommerceContext $context): array
    {
        $found = [];

        foreach ($refs as $ref) {
            if (isset(self::SERVICES[$ref->id])) {
                [$name, $tariff] = self::SERVICES[$ref->id];
                $found[(string) $ref] = new SellableSnapshot(
                    ref: $ref,
                    sku: "SRV-{$ref->id}",
                    name: $name,
                    kind: SellableKind::Service,
                    requiresShipping: false,
                    basePrice: Money::of($tariff, 'RUB'),
                );
            }
        }

        return $found;
    }
}

final class CrmPlugin implements ExtensionPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('flex.crm-commerce', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(SellableProvider::KIND, CrmServiceSellables::class);
    }
}

/** Installs only the core, configured the way a CRM host's config/commerz.php would be. */
final class CrmScenarioTest extends TestCase
{
    use RefreshDatabase;

    private CommerceContext $context;

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.stock', 'unlimited');
        $app['config']->set('commerz.plugins', [CrmPlugin::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $store = Commerz::stores()->create(new CreateStore(code: 'crm', name: 'CRM', defaultCurrency: Currency::of('RUB')));
        $channel = Commerz::channels()->create(new CreateChannel(
            store: $store->id, code: 'manager', name: 'Менеджер', currencies: [Currency::of('RUB')], locales: ['ru'], pricesIncludeTax: true,
        ));
        $this->context = CommerceContext::for($channel->id, Currency::of('RUB'), 'ru');
    }

    #[Test]
    public function it_quotes_host_services_with_only_the_core_installed(): void
    {
        $sellables = Commerz::sellables()->resolveMany([SellableRef::of('crm.service', '77'), SellableRef::of('crm.service', '78')], $this->context);

        $quote = Commerz::quotes()->run(new QuoteInput($this->context, [
            new QuoteLineInput('1', $sellables['crm.service:77'], 2),
            new QuoteLineInput('2', $sellables['crm.service:78'], 1),
        ]));

        $this->assertEquals(Money::of('3700.00', 'RUB'), $quote->total);
        $this->assertSame(
            [['SRV-77', '3000.00', 'sellable', true], ['SRV-78', '700.00', 'sellable', true]],
            array_map(static fn (QuotedLine $line): array => [$line->sellable->sku, $line->subtotal->toDecimal(), $line->priceSource, $line->taxIncluded], $quote->lines),
        );
        $this->assertSame([], $quote->taxLines);
        $this->assertNull($quote->delivery);
    }

    #[Test]
    public function it_treats_stock_as_unlimited_when_the_host_chose_it(): void
    {
        $service = SellableRef::of('crm.service', '77');

        $answer = $this->app->make(StockAvailability::class)->check(new CheckAvailability($this->context->channel, [new AvailabilityLine($service, 1000)]));
        $reserved = $this->app->make(StockReservations::class)->reserve(new ReserveStock('order:1', $this->context->channel, [new ReserveLine($service, 3)]));

        $this->assertTrue($answer->isSatisfiable());
        $this->assertNull($answer->line($service)?->available);
        $this->assertSame(3, $reserved->lines[0]->quantity);
        $this->assertSame([], $reserved->lines[0]->allocations);
    }

    #[Test]
    public function it_reports_unknown_services_and_types_without_a_provider(): void
    {
        try {
            Commerz::sellables()->resolve(SellableRef::of('crm.service', '404'), $this->context);
            $this->fail('expected SellableUnavailable');
        } catch (SellableUnavailable $e) {
            $this->assertSame('crm.service:404', $e->sellable);
        }

        $this->expectException(UnsupportedContribution::class);
        $this->expectExceptionMessage('sellable_provider/variant');
        Commerz::sellables()->resolve(SellableRef::of('variant', '1'), $this->context);
    }
}

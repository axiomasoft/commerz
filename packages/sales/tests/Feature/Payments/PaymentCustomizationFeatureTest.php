<?php

declare(strict_types=1);

namespace Commerz\Sales\Tests\Feature\Payments;

use Commerz\CommerzServiceProvider;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Plugins\CorePlugin;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Facades\Sales;
use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Commerz\Sales\Payments\Data\PaymentCustomizationContext;
use Commerz\Sales\Payments\Data\PaymentMethodDescriptor;
use Commerz\Sales\Payments\Data\PaymentMethodOperation;
use Commerz\Sales\Plugins\SalesPlugin;
use Commerz\Sales\SalesServiceProvider;
use Commerz\Stores\Data\ChannelId;
use Commerz\Testkit\PackageTestCase;
use Illuminate\Foundation\Application;
use Override;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;
use PHPUnit\Framework\Attributes\Test;

final class PaymentCustomizationFeatureTest extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, SalesServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [PaymentCustomizationFixturePlugin::class]);
    }

    #[Test]
    public function resolves_contributed_payment_customizations_from_plugin_graph(): void
    {
        $context = new PaymentCustomizationContext(
            amount: Money::of('120.00', 'EUR'),
            channel: new ChannelId('0190a000-0000-7000-8000-000000000099'),
            locale: 'de-DE',
            methods: [
                new PaymentMethodDescriptor('stripe', 'Card', position: 10),
                new PaymentMethodDescriptor('manual', 'Invoice', position: 20),
                new PaymentMethodDescriptor('cod', 'Cash', position: 30),
            ],
            cart: new CartId('0190a000-0000-7000-8000-000000000088'),
            market: 'EU',
            customer: CustomerRef::of('user', '7'),
            customerGroups: ['wholesale'],
        );

        $resolved = Sales::paymentMethods()->resolve($context);

        self::assertSame(['manual', 'stripe'], array_map(static fn (PaymentMethodDescriptor $m): string => $m->key, $resolved));
        self::assertSame('SEPA Corporate Invoice', $resolved[0]->label);
        self::assertSame('Card (3DS)', $resolved[1]->label);
    }
}

final class PaymentCustomizationFixturePlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('tests.sales-payment-customization', '1.0.0', [CorePlugin::ID, SalesPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(PaymentCustomization::KIND, EuWholesalePaymentCustomization::class);
    }
}

final class EuWholesalePaymentCustomization implements PaymentCustomization
{
    #[Override]
    public function key(): string
    {
        return 'eu_wholesale';
    }

    #[Override]
    public function priority(): int
    {
        return 100;
    }

    #[Override]
    public function customize(PaymentCustomizationContext $context): array
    {
        if ($context->market !== 'EU') {
            return [];
        }

        $ops = [
            PaymentMethodOperation::hide('cod'),
            PaymentMethodOperation::rename('stripe', 'Card (3DS)'),
        ];

        if (in_array('wholesale', $context->customerGroups, true)) {
            $ops[] = PaymentMethodOperation::rename('manual', 'SEPA Corporate Invoice');
            $ops[] = PaymentMethodOperation::move('manual', 0);
        }

        return $ops;
    }
}

<?php

declare(strict_types=1);

use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Commerz\Sales\Payments\Data\PaymentCustomizationContext;
use Commerz\Sales\Payments\Data\PaymentMethodDescriptor;
use Commerz\Sales\Payments\Data\PaymentMethodOperation;
use Commerz\Sales\Payments\Internal\ContributedPaymentMethodResolver;
use Commerz\Stores\Data\ChannelId;
use Packman\Plugins\Contracts\Contributions\Contributions;

function emptyContributionsStub(): Contributions
{
    return new class implements Contributions
    {
        public function has(string $kind, string $key): bool
        {
            return false;
        }

        public function get(string $kind, string $key, string $expectedInterface): object
        {
            throw new RuntimeException("Unexpected get({$kind}, {$key})");
        }

        public function keys(string $kind): array
        {
            return [];
        }

        public function all(string $kind, string $expectedInterface): array
        {
            return [];
        }
    };
}

it('hides, renames, and sorts payment methods deterministically by cart, market, and customer', function (): void {
    $resolver = new ContributedPaymentMethodResolver(emptyContributionsStub());

    $methods = [
        new PaymentMethodDescriptor('stripe', 'Credit Card', position: 10),
        new PaymentMethodDescriptor('manual', 'Bank Transfer', position: 20),
        new PaymentMethodDescriptor('cod', 'Cash on Delivery', position: 30),
        new PaymentMethodDescriptor('stored_value.wallet', 'Store Credit', position: 40),
    ];

    // Rule 1 (priority 100): hide COD for EU market or high-value carts >= 500.00
    $hideCodRule = new class implements PaymentCustomization
    {
        public function key(): string
        {
            return 'eu_hide_cod';
        }

        public function priority(): int
        {
            return 100;
        }

        public function customize(PaymentCustomizationContext $context): array
        {
            if ($context->market === 'EU' || $context->amount->amountMinor >= 50000) {
                return [PaymentMethodOperation::hide('cod')];
            }

            return [];
        }
    };

    // Rule 2 (priority 50): for B2B VIP customer group, rename manual to "Corporate Invoice (Net 30)" and move it first!
    $b2bRule = new class implements PaymentCustomization
    {
        public function key(): string
        {
            return 'b2b_invoice_priority';
        }

        public function priority(): int
        {
            return 50;
        }

        public function customize(PaymentCustomizationContext $context): array
        {
            if (! in_array('b2b_vip', $context->customerGroups, true)) {
                return [];
            }

            return [
                PaymentMethodOperation::rename('manual', 'Corporate Invoice (Net 30)'),
                PaymentMethodOperation::move('manual', 0),
                // Trying to rename already-hidden cod must be a no-op; unknown key must be safely ignored.
                PaymentMethodOperation::rename('cod', 'Should Stay Hidden'),
                PaymentMethodOperation::move('nonexistent', 0),
            ];
        }
    };

    $context = new PaymentCustomizationContext(
        amount: Money::of('750.00', 'EUR'),
        channel: new ChannelId('0190a000-0000-7000-8000-000000000010'),
        locale: 'en-GB',
        methods: $methods,
        cart: new CartId('0190a000-0000-7000-8000-000000000020'),
        market: 'EU',
        shippingCountry: 'DE',
        customer: CustomerRef::of('company', '42'),
        customerGroups: ['b2b_vip'],
    );

    // Deliberately pass in reverse priority order to prove deterministic priority DESC, key ASC sorting.
    $resolved = $resolver->resolve($context, [$b2bRule, $hideCodRule]);

    expect(array_map(static fn (PaymentMethodDescriptor $m): string => $m->key, $resolved))
        ->toBe(['manual', 'stripe', 'stored_value.wallet'])
        ->and($resolved[0]->label)->toBe('Corporate Invoice (Net 30)')
        ->and($resolved[0]->position)->toBe(0)
        ->and($resolved[1]->label)->toBe('Credit Card');
});

it('breaks priority ties by key ascending so higher-lexicographic key wins last-write on rename/move', function (): void {
    $resolver = new ContributedPaymentMethodResolver(emptyContributionsStub());

    $ruleA = new class implements PaymentCustomization
    {
        public function key(): string
        {
            return 'a_first';
        }

        public function priority(): int
        {
            return 10;
        }

        public function customize(PaymentCustomizationContext $context): array
        {
            return [PaymentMethodOperation::rename('stripe', 'First Label')];
        }
    };

    $ruleB = new class implements PaymentCustomization
    {
        public function key(): string
        {
            return 'b_second';
        }

        public function priority(): int
        {
            return 10;
        }

        public function customize(PaymentCustomizationContext $context): array
        {
            return [PaymentMethodOperation::rename('stripe', 'Second Label')];
        }
    };

    $context = new PaymentCustomizationContext(
        amount: Money::of('10.00', 'USD'),
        channel: new ChannelId('0190a000-0000-7000-8000-000000000010'),
        locale: 'en-US',
        methods: [new PaymentMethodDescriptor('stripe', 'Original')],
    );

    $firstOrder = $resolver->resolve($context, [$ruleB, $ruleA]);
    $secondOrder = $resolver->resolve($context, [$ruleA, $ruleB]);

    expect($firstOrder[0]->label)->toBe('Second Label')
        ->and($secondOrder[0]->label)->toBe('Second Label');
});

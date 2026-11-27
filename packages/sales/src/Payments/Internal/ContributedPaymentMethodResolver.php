<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Commerz\Sales\Payments\Contracts\PaymentMethodResolver;
use Commerz\Sales\Payments\Data\PaymentCustomizationContext;
use Commerz\Sales\Payments\Data\PaymentMethodDescriptor;
use Commerz\Sales\Payments\Enums\PaymentCustomizationAction;
use Override;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Applies registered `payment_customization` contributions in deterministic `priority DESC, key ASC` order.
 */
#[Internal]
final readonly class ContributedPaymentMethodResolver implements PaymentMethodResolver
{
    public function __construct(
        private Contributions $contributions,
    ) {}

    /**
     * @param  list<PaymentCustomization>  $customizers
     * @return list<PaymentMethodDescriptor>
     */
    #[Override]
    public function resolve(PaymentCustomizationContext $context, array $customizers = []): array
    {
        $rules = array_map(
            fn (string $key): PaymentCustomization => $this->contributions->get(PaymentCustomization::KIND, $key, PaymentCustomization::class),
            $this->contributions->keys(PaymentCustomization::KIND),
        );

        foreach ($customizers as $customizer) {
            $rules[] = $customizer;
        }

        usort(
            $rules,
            static fn (PaymentCustomization $a, PaymentCustomization $b): int => [$b->priority(), $a->key()] <=> [$a->priority(), $b->key()],
        );

        /** @var array<string, array{descriptor: PaymentMethodDescriptor, index: int}> $active */
        $active = [];

        foreach ($context->methods as $index => $method) {
            $active[$method->key] ??= ['descriptor' => $method, 'index' => $index];
        }

        /** @var array<string, true> $hidden */
        $hidden = [];

        foreach ($rules as $rule) {
            $currentMethods = self::ordered($active);
            $ruleContext = new PaymentCustomizationContext(
                amount: $context->amount,
                channel: $context->channel,
                locale: $context->locale,
                methods: $currentMethods,
                cart: $context->cart,
                market: $context->market,
                shippingCountry: $context->shippingCountry,
                billingCountry: $context->billingCountry,
                customer: $context->customer,
                customerGroups: $context->customerGroups,
                meta: $context->meta,
            );

            foreach ($rule->customize($ruleContext) as $operation) {
                $key = $operation->methodKey;

                if (isset($hidden[$key]) || ! isset($active[$key])) {
                    continue;
                }

                if ($operation->action === PaymentCustomizationAction::Hide) {
                    $hidden[$key] = true;
                    unset($active[$key]);

                    continue;
                }

                if ($operation->action === PaymentCustomizationAction::Rename && $operation->name !== null) {
                    $active[$key]['descriptor'] = $active[$key]['descriptor']->withLabel($operation->name);

                    continue;
                }

                if ($operation->action === PaymentCustomizationAction::Move && $operation->position !== null) {
                    $active[$key]['descriptor'] = $active[$key]['descriptor']->withPosition($operation->position);
                }
            }
        }

        return self::ordered($active);
    }

    /**
     * @param  array<string, array{descriptor: PaymentMethodDescriptor, index: int}>  $active
     * @return list<PaymentMethodDescriptor>
     */
    private static function ordered(array $active): array
    {
        $entries = array_values($active);
        usort(
            $entries,
            static fn (array $a, array $b): int => [$a['descriptor']->position, $a['index']] <=> [$b['descriptor']->position, $b['index']],
        );

        return array_map(
            static fn (array $entry): PaymentMethodDescriptor => $entry['descriptor'],
            $entries,
        );
    }
}

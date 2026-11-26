<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Contracts;

use Commerz\Sales\Payments\Data\PaymentCustomizationContext;
use Commerz\Sales\Payments\Data\PaymentMethodDescriptor;
use Packman\Attributes\Api;

/** Resolves checkout payment methods by applying all registered {@see PaymentCustomization} contributions. */
#[Api(since: '0.1')]
interface PaymentMethodResolver
{
    /**
     * Applies registered `payment_customization` contributions (plus any extra `$customizers`) in deterministic
     * `priority DESC, key ASC` order and returns the resulting visible, renamed, and sorted methods.
     *
     * @param  list<PaymentCustomization>  $customizers
     * @return list<PaymentMethodDescriptor>
     */
    public function resolve(PaymentCustomizationContext $context, array $customizers = []): array;
}

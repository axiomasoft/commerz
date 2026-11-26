<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Sales\Carts\Data\CartId;
use Commerz\Sales\Gateways\Internal\GatewayMeta;
use Commerz\Sales\Payments\Contracts\PaymentCustomization;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/**
 * Evaluation context for {@see PaymentCustomization}: cart, market, customer,
 * amount, and candidate payment methods.
 */
#[Api(since: '0.1')]
final readonly class PaymentCustomizationContext
{
    private const string CODE = 'sales.invalid_payment';

    /**
     * @param  list<PaymentMethodDescriptor>  $methods
     * @param  list<string>  $customerGroups
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public Money $amount,
        public ChannelId $channel,
        public string $locale,
        public array $methods,
        public ?CartId $cart = null,
        public ?string $market = null,
        public ?string $shippingCountry = null,
        public ?string $billingCountry = null,
        public ?CustomerRef $customer = null,
        public array $customerGroups = [],
        public array $meta = [],
    ) {
        if ($amount->isNegative()) {
            throw new InvalidCommand('amount', 'must not be negative', self::CODE);
        }

        if (trim($locale) === '') {
            throw new InvalidCommand('locale', 'must be non-empty', self::CODE);
        }

        foreach ($customerGroups as $group) {
            if (trim($group) === '') {
                throw new InvalidCommand('customerGroups', 'must contain non-empty strings', self::CODE);
            }
        }

        GatewayMeta::assert($meta, 'meta', self::CODE);
    }
}

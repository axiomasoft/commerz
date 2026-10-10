<?php

declare(strict_types=1);

namespace Commerz\Sales\Orders\Data;

use Commerz\Attributes\Api;
use Commerz\Context\CommerceContext;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Sales\Carts\Data\EnterCode;

/**
 * An order an operator assembles for a customer (D31): a cart of kind `draft`, edited with the commands of `Carts`
 * and placed with `Checkout::place`. Lines of the same sellable are added up.
 */
#[Api(since: '0.1')]
final readonly class DraftOrder
{
    /** @var list<string> */
    public array $codes;

    /**
     * @param  list<DraftLine>  $lines
     * @param  list<string>  $codes
     */
    public function __construct(
        public CommerceContext $context,
        public array $lines,
        public ?ActorRef $actor = null,
        array $codes = [],
        public ?Address $shippingAddress = null,
        public ?Address $billingAddress = null,
        public ?string $deliveryMethod = null,
    ) {
        $this->codes = array_values(array_unique(array_map(EnterCode::normalize(...), $codes)));

        if ($deliveryMethod !== null && ($deliveryMethod === '' || strlen($deliveryMethod) > 64)) {
            throw new InvalidCommand('deliveryMethod', 'must be 1..64 bytes', 'sales.invalid_delivery_method');
        }
    }
}

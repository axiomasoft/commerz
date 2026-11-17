<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Packman\Attributes\Api;

/** Marks a created fulfillment as shipped, with the carrier and tracking number the operator hands over (or neither). */
#[Api(since: '0.1')]
final readonly class ShipFulfillment
{
    public function __construct(
        public FulfillmentId $fulfillment,
        public ?string $carrier = null,
        public ?string $tracking = null,
        public ?ActorRef $actor = null,
    ) {
        foreach (['carrier' => $carrier, 'tracking' => $tracking] as $field => $value) {
            if ($value !== null && (trim($value) === '' || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                throw new InvalidCommand($field, 'must be a non-empty UTF-8 text without NUL', 'sales.invalid_fulfillment');
            }
        }
    }
}

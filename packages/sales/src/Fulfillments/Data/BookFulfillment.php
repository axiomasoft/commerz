<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;

/**
 * Books the shipment of a fulfillment with the carrier of the delivery the order was placed with, then ships it. The
 * dimensions complete the parcel; `$meta` goes to the carrier as is.
 */
#[Api(since: '0.1')]
final readonly class BookFulfillment
{
    private const string CODE = 'sales.invalid_fulfillment';

    /** @param  array<string, scalar>  $meta */
    public function __construct(
        public FulfillmentId $fulfillment,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
        public array $meta = [],
        public ?ActorRef $actor = null,
    ) {
        foreach (['lengthMm' => $lengthMm, 'widthMm' => $widthMm, 'heightMm' => $heightMm] as $field => $size) {
            if ($size !== null && $size < 1) {
                throw new InvalidCommand($field, 'must be positive', self::CODE);
            }
        }

        self::assertMeta($meta);
    }

    /** @param  array<array-key, mixed>  $meta  checked for callers without static analysis */
    private static function assertMeta(array $meta): void
    {
        foreach ($meta as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_scalar($value)
                || str_contains($key, "\0") || (is_string($value) && str_contains($value, "\0"))
                || json_encode($value) === false) {
                throw new InvalidCommand('meta', 'must map non-empty string keys to JSON scalars without NUL', self::CODE);
            }
        }
    }
}

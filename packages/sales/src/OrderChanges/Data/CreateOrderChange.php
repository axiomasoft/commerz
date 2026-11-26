<?php

declare(strict_types=1);

namespace Commerz\Sales\OrderChanges\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Sales\Orders\Data\OrderId;
use Packman\Attributes\Api;

/** Opens a new draft OrderChange for an editable order. */
#[Api(since: '0.1')]
final readonly class CreateOrderChange
{
    private const string CODE = 'sales.invalid_order_change';

    public function __construct(
        public OrderId $order,
        public ?string $description = null,
        public ?ActorRef $actor = null,
    ) {
        if ($description !== null && ($description === '' || strlen($description) > 1000 || ! mb_check_encoding($description, 'UTF-8') || str_contains($description, "\0"))) {
            throw new InvalidCommand('description', 'must be null or 1..1000 bytes of UTF-8 without NUL', self::CODE);
        }
    }
}

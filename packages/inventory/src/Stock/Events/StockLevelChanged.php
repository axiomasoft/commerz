<?php

declare(strict_types=1);

namespace Commerz\Inventory\Stock\Events;

use Commerz\Inventory\Stock\Data\StockLevelView;
use Commerz\Inventory\Stock\Enums\MovementKind;
use Packman\Attributes\Api;

/**
 * A level changed in a committed operation; one event per level and operation, carrying its final state.
 * `kind` is the last movement and `delta` the net on-hand change; both are null/0 when only reserved or safety
 * stock changed.
 */
#[Api(since: '0.1')]
final readonly class StockLevelChanged
{
    public function __construct(
        public StockLevelView $level,
        public ?MovementKind $kind,
        public int $delta,
        public ?string $reference,
    ) {}
}

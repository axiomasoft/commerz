<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Common\Data\LineQuantity;

/**
 * Accepts the goods of a return. Accepting is not restocking (REF-01): only the units listed in `$restock` go back on
 * hand, into `$locationCode` (the stock decides when null); the rest stays out, as defective or incomplete.
 */
#[Api(since: '0.1')]
final readonly class ReceiveReturn
{
    /** @param  list<LineQuantity>  $restock  one entry per line, each at most the units of that line in the return */
    public function __construct(
        public ReturnId $return,
        public array $restock = [],
        public ?string $locationCode = null,
        public ?ActorRef $actor = null,
    ) {
        $ids = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $restock);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('restock', 'must name each line once', 'sales.invalid_lines');
        }

        StockLines::assertLocation($locationCode);
    }
}

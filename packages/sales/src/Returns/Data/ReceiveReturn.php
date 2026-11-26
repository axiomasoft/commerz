<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Ports\Stock\Data\StockLines;
use Commerz\Sales\Common\Data\LineQuantity;
use Commerz\Sales\Returns\Enums\ReturnDisposition;
use Commerz\Sales\Returns\Enums\ReturnReason;
use Packman\Attributes\Api;

/**
 * Accepts the goods of a return. Accepting is not restocking (REF-01): only units assigned `ReturnDisposition::Restock`
 * (or listed in `$restock`) go back on hand into `$locationCode`; units assigned `ReturnDisposition::DamagedWriteOff`
 * write a `write_off` stock movement and NEVER increase sellable `on_hand` (`INV-INV-002`).
 */
#[Api(since: '0.1')]
final readonly class ReceiveReturn
{
    /**
     * @param  list<LineQuantity>  $restock  one entry per line, each at most the units of that line in the return
     * @param  list<ReturnLineDisposition>  $dispositions  explicit per-line dispositions (`Restock` or `DamagedWriteOff`)
     */
    public function __construct(
        public ReturnId $return,
        public array $restock = [],
        public ?string $locationCode = null,
        public ?ActorRef $actor = null,
        public ?ReturnDisposition $disposition = null,
        public ?ReturnReason $reason = null,
        public array $dispositions = [],
    ) {
        $ids = array_map(static fn (LineQuantity $line): string => $line->lineId->value, $restock);

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidCommand('restock', 'must name each line once', 'sales.invalid_lines');
        }

        if ($restock !== [] && $dispositions !== []) {
            throw new InvalidCommand('dispositions', 'cannot combine restock lines with explicit dispositions', 'sales.invalid_return');
        }

        if ($restock !== [] && $disposition === ReturnDisposition::DamagedWriteOff) {
            throw new InvalidCommand('disposition', 'cannot combine DamagedWriteOff disposition with restock lines', 'sales.invalid_return');
        }

        $seenDispositions = [];

        foreach ($dispositions as $item) {
            $pairKey = "{$item->lineId->value}:{$item->disposition->value}";

            if (isset($seenDispositions[$pairKey])) {
                throw new InvalidCommand('dispositions', 'must name each line and disposition pair once', 'sales.invalid_lines');
            }

            $seenDispositions[$pairKey] = true;
        }

        StockLines::assertLocation($locationCode);
    }
}

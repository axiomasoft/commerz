<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Inventory\Common\Internal\LevelRow;
use Commerz\Inventory\Common\Internal\StockLedger;
use Commerz\Inventory\Internal\Data\Discrepancy;
use Commerz\Inventory\Internal\Data\ReconciliationReport;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

/**
 * Checks the stock tables against each other (INV-03, INV-04) and repairs the one derived number that is safe to
 * rebuild. Reads share one `repeatable read` read-only snapshot, so concurrent traffic cannot fake a discrepancy.
 */
#[Internal]
final readonly class Reconciler
{
    private const string OUTSTANDING = '(ln.units - ln.consumed_units - ln.released_units - ln.expired_units)';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private StockLedger $ledger,
        private Transactions $transactions,
    ) {}

    public function check(): ReconciliationReport
    {
        $db = $this->db();
        $nested = $db->transactionLevel() > 0;
        $db->beginTransaction();

        try {
            if (! $nested) {
                $db->statement('set transaction isolation level repeatable read read only');
            }

            return new ReconciliationReport([
                ...$this->ledgerSum(),
                ...$this->ledgerBalance(),
                ...$this->reservedSum(),
                ...$this->reservationState(),
                ...$this->transferState(),
            ]);
        } finally {
            $db->rollBack();
        }
    }

    /**
     * Sets `reserved` of every level whose reservation lines disagree to the sum of the lines (the lines are the
     * source of truth). Each level is re-checked under its lock, so a level that settled meanwhile is left alone.
     *
     * @return int levels repaired
     */
    public function repairReserved(): int
    {
        $repaired = 0;

        foreach ($this->check()->ofKind(Discrepancy::RESERVED_SUM) as $discrepancy) {
            $id = substr($discrepancy->ref, strlen('stock_level:'));
            $repaired += $this->transactions->run(fn (): int => $this->repairLevel($id));
        }

        return $repaired;
    }

    private function repairLevel(string $id): int
    {
        $pair = $this->db()->table($this->tables->table('stock_levels'))->where('id', $id)->first(['stock_item_id', 'location_id']);

        if ($pair === null) {
            return 0;
        }

        return $this->ledger->within(function () use ($id, $pair): int {
            $locked = $this->ledger->lock([[Rows::string($pair, 'stock_item_id'), Rows::string($pair, 'location_id')]]);
            $level = array_values($locked)[0] ?? null;

            if (! $level instanceof LevelRow) {
                return 0;
            }

            $held = $this->db()->selectOne(
                'select coalesce(sum('.self::OUTSTANDING.'::numeric * ln.quantity_per_unit), 0)::text as held'
                ." from {$this->lines()} ln where ln.stock_level_id = ?",
                [$id],
                useReadPdo: false,
            );
            $sum = is_object($held) ? Rows::string($held, 'held') : '0';

            if ($sum === (string) $level->reserved) {
                return 0;
            }

            $this->ledger->repairReserved($level, (int) $sum);

            return 1;
        });
    }

    /** @return list<Discrepancy> */
    private function ledgerSum(): array
    {
        $rows = $this->db()->select(
            'select lv.id, lv.on_hand::text as actual, coalesce(sum(m.delta::numeric), 0)::text as expected'
            ." from {$this->levels()} lv left join {$this->movements()} m on m.stock_level_id = lv.id"
            .' group by lv.id, lv.on_hand having lv.on_hand::numeric <> coalesce(sum(m.delta::numeric), 0) order by lv.id',
        );

        return $this->map($rows, Discrepancy::LEDGER_SUM, 'stock_level:');
    }

    /** @return list<Discrepancy> */
    private function ledgerBalance(): array
    {
        $rows = $this->db()->select(
            'select lv.id, lv.on_hand::text as actual, coalesce(last.balance_after, 0)::text as expected'
            ." from {$this->levels()} lv left join lateral ("
            ."select m.balance_after from {$this->movements()} m where m.stock_level_id = lv.id order by m.id desc limit 1"
            .') last on true where lv.on_hand <> coalesce(last.balance_after, 0) order by lv.id',
        );

        return $this->map($rows, Discrepancy::LEDGER_BALANCE, 'stock_level:');
    }

    /** @return list<Discrepancy> */
    private function reservedSum(): array
    {
        $rows = $this->db()->select(
            'select lv.id, lv.reserved::text as actual, coalesce(sum('.self::OUTSTANDING.'::numeric * ln.quantity_per_unit), 0)::text as expected'
            ." from {$this->levels()} lv left join {$this->lines()} ln on ln.stock_level_id = lv.id"
            .' group by lv.id, lv.reserved'
            .' having lv.reserved::numeric <> coalesce(sum('.self::OUTSTANDING.'::numeric * ln.quantity_per_unit), 0) order by lv.id',
        );

        return $this->map($rows, Discrepancy::RESERVED_SUM, 'stock_level:');
    }

    /** @return list<Discrepancy> */
    private function reservationState(): array
    {
        $headers = $this->tables->table('stock_reservations');
        $rows = $this->db()->select(
            'select r.demand, r.state, count(ln.id) as lines, count(*) filter (where '.self::OUTSTANDING.' > 0) as outstanding,'
            .' coalesce(sum(ln.consumed_units), 0)::text as consumed, coalesce(sum(ln.expired_units), 0)::text as expired_units,'
            .' coalesce(r.expires_at <= now(), false) as expired'
            ." from {$headers} r left join {$this->lines()} ln on ln.reservation_id = r.id and ln.generation = r.generation"
            .' group by r.id, r.demand, r.state order by r.demand',
        );
        $found = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $outstanding = Rows::int($row, 'outstanding');
            $state = Rows::string($row, 'state');

            if ($state === 'closed' && $outstanding > 0) {
                $found[] = new Discrepancy(Discrepancy::RESERVATION_STATE, 'demand:'.Rows::string($row, 'demand'), 'closed without outstanding lines', "closed with {$outstanding} outstanding");
            } elseif ($state === 'active' && $outstanding === 0 && Rows::string($row, 'consumed') === '0'
                && Rows::int($row, 'lines') > 0
                && (! Rows::bool($row, 'expired') || Rows::string($row, 'expired_units') === '0')) {
                // Line-free unlimited demands stay active; lazy expiry leaves an expired header for the sweeper.
                $found[] = new Discrepancy(Discrepancy::RESERVATION_STATE, 'demand:'.Rows::string($row, 'demand'), 'closed', 'active without outstanding lines');
            }
        }

        return $found;
    }

    /** @return list<Discrepancy> */
    private function transferState(): array
    {
        $transfers = $this->tables->table('stock_transfers');
        $transferLines = $this->tables->table('stock_transfer_lines');
        $headers = $this->tables->table('stock_reservations');

        $unshipped = $this->db()->select(
            "select t.id from {$transfers} t where t.state = 'in_transit' and not exists ("
            ."select 1 from {$headers} r join {$this->lines()} ln on ln.reservation_id = r.id"
            ." where r.demand = 'transfer:' || t.id::text and r.state = 'closed' and ln.consumed_units > 0) order by t.id",
        );
        $stranded = $this->db()->select(
            "select t.id, sum(tl.quantity - tl.received - tl.lost)::text as actual from {$transfers} t"
            ." join {$transferLines} tl on tl.transfer_id = t.id where t.state = 'completed'"
            .' group by t.id having sum(tl.quantity - tl.received - tl.lost) > 0 order by t.id',
        );

        $found = [];

        foreach ($unshipped as $row) {
            if (is_object($row)) {
                $found[] = new Discrepancy(Discrepancy::TRANSFER_STATE, 'transfer:'.Rows::string($row, 'id'), 'source reservation consumed', 'in transit without a consumed source reservation');
            }
        }

        foreach ($stranded as $row) {
            if (is_object($row)) {
                $found[] = new Discrepancy(Discrepancy::TRANSFER_STATE, 'transfer:'.Rows::string($row, 'id'), '0 in transit', Rows::string($row, 'actual').' in transit');
            }
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<Discrepancy>
     */
    private function map(array $rows, string $kind, string $prefix): array
    {
        $found = [];

        foreach ($rows as $row) {
            if (is_object($row)) {
                $found[] = new Discrepancy($kind, $prefix.Rows::string($row, 'id'), Rows::string($row, 'expected'), Rows::string($row, 'actual'));
            }
        }

        return $found;
    }

    private function levels(): string
    {
        return $this->tables->table('stock_levels');
    }

    private function movements(): string
    {
        return $this->tables->table('stock_movements');
    }

    private function lines(): string
    {
        return $this->tables->table('stock_reservation_lines');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

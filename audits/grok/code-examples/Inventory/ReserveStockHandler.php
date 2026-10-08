<?php

declare(strict_types=1);

namespace Commerz\Inventory\Internal\Handlers;

use Commerz\Inventory\Contracts\StockReservations;
use Commerz\Inventory\Data\ReservationLine;
use Commerz\Inventory\Data\ReservationResult;
use Commerz\Inventory\Data\ReservedLine;
use Commerz\Inventory\Data\ReserveStock;
use Commerz\Inventory\Exceptions\InsufficientStock;
use Commerz\Inventory\Exceptions\ReservationConflict;
use Commerz\Inventory\Exceptions\UnknownStockLevel;
use Commerz\Schema\Contracts\TableNames;
use Commerz\Schema\Internal\Row;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;
use Override;

/**
 * Резерв остатка без гонок (prototype E2–E4, database.md §5).
 *
 * 1. Сортировка по stock_level_id — одинаковый порядок блокировок у всех транзакций → нет взаимоблокировок.
 * 2. Ленивое истечение броней этого уровня — одним выражением вместе со счётчиком (консистентно само по себе).
 * 3. INSERT брони ДО счётчика: уникальный естественный ключ сериализует повторы одной команды
 *    (второй ждёт первого и получает replay) — таблица command_receipts не нужна.
 * 4. Условный UPDATE счётчика: проверка и запись атомарны в одной строке; read-then-write (Medusa) теряет обновления.
 * Время — только now() БД: один источник часов для всех серверов приложения.
 */
final readonly class ReserveStockHandler implements StockReservations
{
    public function __construct(private ConnectionInterface $db, private TableNames $tables) {}

    #[Override]
    public function reserve(ReserveStock $command): ReservationResult
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('ReserveStock must run inside the caller transaction (order placement).');
        }
        $this->db->statement("SET LOCAL lock_timeout = '3s'");

        $lines = $command->lines;
        usort($lines, static fn (ReservationLine $a, ReservationLine $b): int => [$a->stockLevelId, $a->lineRef] <=> [$b->stockLevelId, $b->lineRef]);

        $result = [];
        foreach ($lines as $line) {
            $this->releaseExpired($line->stockLevelId);
            $result[] = $this->reserveLine($command, $line);
        }

        return new ReservationResult($result);
    }

    private function releaseExpired(string $levelId): void
    {
        [$levels, $reservations] = [$this->tables->of('stock_levels'), $this->tables->of('stock_reservations')];

        $this->db->statement(<<<SQL
            WITH expired AS (
                UPDATE {$reservations} SET state = 'expired'
                 WHERE stock_level_id = :level AND state = 'active' AND expires_at < now()
             RETURNING quantity - consumed AS qty
            )
            UPDATE {$levels}
               SET reserved = reserved - (SELECT sum(qty) FROM expired), version = version + 1, updated_at = now()
             WHERE id = :level2 AND EXISTS (SELECT 1 FROM expired)
            SQL, ['level' => $levelId, 'level2' => $levelId]);
    }

    private function reserveLine(ReserveStock $command, ReservationLine $line): ReservedLine
    {
        [$levels, $reservations] = [$this->tables->of('stock_levels'), $this->tables->of('stock_reservations')];

        // 3. Бронь: новая, или «воскрешение» истёкшей/освобождённой с тем же ключом. Активная/списанная — повтор.
        $inserted = $this->db->selectOne(<<<SQL
            INSERT INTO {$reservations} (id, stock_level_id, demand_type, demand_id, line_ref, quantity, consumed, state, expires_at)
            VALUES (:id, :level, :type, :demand, :line, :qty, 0, 'active',
                    CASE WHEN CAST(:ttl AS int) IS NULL THEN NULL ELSE now() + make_interval(mins => CAST(:ttl2 AS int)) END)
            ON CONFLICT (demand_type, demand_id, line_ref, stock_level_id) DO UPDATE
               SET quantity = EXCLUDED.quantity, consumed = 0, state = 'active', expires_at = EXCLUDED.expires_at
             WHERE {$reservations}.state IN ('expired', 'released')
            RETURNING id
            SQL, [
            'id' => (string) Str::uuid7(),
            'level' => $line->stockLevelId,
            'type' => $command->demandType->value,
            'demand' => $command->demandId,
            'line' => $line->lineRef,
            'qty' => $line->quantity,
            'ttl' => $command->ttlMinutes,
            'ttl2' => $command->ttlMinutes,
        ]);

        if ($inserted === null) {
            return $this->replay($command, $line);
        }

        // 4. Счётчик: атомарная проверка доступности.
        $updated = $this->db->update(<<<SQL
            UPDATE {$levels}
               SET reserved = reserved + :qty, version = version + 1, updated_at = now()
             WHERE id = :level
               AND (allows_negative OR on_hand - reserved - safety_stock >= :qty2)
            SQL, ['qty' => $line->quantity, 'qty2' => $line->quantity, 'level' => $line->stockLevelId]);

        if ($updated === 0) {
            // исключение откатывает всю транзакцию вызывающего, включая вставленную бронь
            $level = $this->db->selectOne(
                "SELECT on_hand - reserved - safety_stock AS available FROM {$levels} WHERE id = :level",
                ['level' => $line->stockLevelId],
            ) ?? throw UnknownStockLevel::id($line->stockLevelId);

            throw new InsufficientStock($line->stockLevelId, $line->lineRef, $line->quantity, max(0, Row::of($level)->int('available')));
        }

        return new ReservedLine(Row::of($inserted)->string('id'), $line->stockLevelId, $line->lineRef, $line->quantity, replayed: false);
    }

    private function replay(ReserveStock $command, ReservationLine $line): ReservedLine
    {
        $existing = Row::of($this->db->selectOne(
            "SELECT id, quantity FROM {$this->tables->of('stock_reservations')}
              WHERE demand_type = :type AND demand_id = :demand AND line_ref = :line AND stock_level_id = :level",
            ['type' => $command->demandType->value, 'demand' => $command->demandId, 'line' => $line->lineRef, 'level' => $line->stockLevelId],
        ) ?? throw new LogicException('Reservation vanished between conflict and read.'));

        if ($existing->int('quantity') !== $line->quantity) {
            throw ReservationConflict::quantityMismatch($line->lineRef, $existing->int('quantity'), $line->quantity);
        }

        return new ReservedLine($existing->string('id'), $line->stockLevelId, $line->lineRef, $line->quantity, replayed: true);
    }
}

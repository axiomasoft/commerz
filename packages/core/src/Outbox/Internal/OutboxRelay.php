<?php

declare(strict_types=1);

namespace Commerz\Outbox\Internal;

use Commerz\Outbox\Contracts\OutboxConsumer;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Claim → deliver → mark. A batch of ready rows is claimed in one statement (`FOR UPDATE SKIP LOCKED`, so parallel
 * relays never take the same row) which also counts the attempt and leases the row for `LEASE_SECONDS`. Delivery runs
 * outside any transaction, so consumers may do network calls; a crashed relay's rows become ready again after the
 * lease. A failure reschedules the row with exponential backoff capped at one hour.
 */
final readonly class OutboxRelay
{
    public const string CONSUMER_TAG = 'commerz.outbox_consumers';

    public const int LEASE_SECONDS = 300;

    public const int BASE_BACKOFF_SECONDS = 30;

    public const int MAX_BACKOFF_SECONDS = 3600;

    public function __construct(
        private TableNames $tables,
        private ConnectionResolverInterface $connections,
        private Dispatcher $events,
        private Container $container,
    ) {}

    public function relay(int $batch): RelayReport
    {
        if ($batch < 1 || $batch > 1000) {
            throw new InvalidArgumentException('The relay batch is from 1 to 1000 messages.');
        }

        $connection = $this->connections->connection($this->tables->connection());
        $table = $this->tables->table('outbox_messages');

        $rows = $connection->select(<<<SQL
            with ready as (
                select id from {$table}
                where delivered_at is null and available_at <= now()
                order by id
                limit ?
                for update skip locked
            )
            update {$table} m
            set attempts = m.attempts + 1, available_at = now() + make_interval(secs => ?)
            from ready
            where m.id = ready.id
            returning m.id, m.event_id, m.type, m.version, m.aggregate_ref, m.payload::text as payload, m.occurred_at, m.attempts
            SQL, [$batch, self::LEASE_SECONDS], useReadPdo: false);

        usort($rows, static fn (mixed $a, mixed $b): int => self::int($a, 'id') <=> self::int($b, 'id'));

        $consumers = $this->consumers();
        $delivered = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $id = self::int($row, 'id');

            try {
                $event = self::event($row);
                $this->events->dispatch(new IntegrationEventDelivered($event));

                foreach ($consumers as $consumer) {
                    $consumer->consume($event);
                }
            } catch (Throwable $e) {
                $this->fail($connection, $table, $id, self::int($row, 'attempts'), $e);
                $failed++;

                continue;
            }

            $connection->update("update {$table} set delivered_at = now(), last_error = null where id = ?", [$id]);
            $delivered++;
        }

        return new RelayReport(count($rows), $delivered, $failed);
    }

    public static function backoffSeconds(int $attempts): int
    {
        $exponent = max(0, min($attempts - 1, 20));

        return min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * 2 ** $exponent);
    }

    private function fail(ConnectionInterface $connection, string $table, int $id, int $attempts, Throwable $e): void
    {
        $error = mb_strcut($e::class.': '.$e->getMessage(), 0, 2000);

        $connection->update(
            "update {$table} set available_at = now() + make_interval(secs => ?), last_error = ? where id = ?",
            [self::backoffSeconds($attempts), $error, $id],
        );
    }

    /** @return list<OutboxConsumer> */
    private function consumers(): array
    {
        $consumers = [];

        foreach ($this->container->tagged(self::CONSUMER_TAG) as $consumer) {
            if (! $consumer instanceof OutboxConsumer) {
                throw new LogicException('Services tagged '.self::CONSUMER_TAG.' must implement '.OutboxConsumer::class.'; got '.get_debug_type($consumer).'.');
            }

            $consumers[] = $consumer;
        }

        return $consumers;
    }

    private static function event(mixed $row): IntegrationEvent
    {
        $payload = json_decode(self::string($row, 'payload'), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $payload */
        $payload = is_array($payload) ? $payload : [];

        return new IntegrationEvent(
            self::string($row, 'event_id'),
            self::string($row, 'type'),
            self::int($row, 'version'),
            self::string($row, 'aggregate_ref'),
            $payload,
            new DateTimeImmutable(self::string($row, 'occurred_at')),
        );
    }

    private static function string(mixed $row, string $column): string
    {
        $value = self::field($row, $column);

        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $row, string $column): int
    {
        $value = self::field($row, $column);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function field(mixed $row, string $column): mixed
    {
        return is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
    }
}

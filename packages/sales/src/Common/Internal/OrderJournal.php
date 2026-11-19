<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\ActorRef;
use Commerz\Sales\Orders\Data\OrderEventView;
use Commerz\Sales\Orders\Data\OrderId;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;

/** The append-only history of an order (`order_events`): written in the transaction of the change it records. */
#[Internal]
final readonly class OrderJournal
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /**
     * @param  string  $type  `[a-z][a-z0-9_]*`, e.g. `placed`
     * @param  array<string, scalar|null>  $data
     */
    public function append(OrderId $order, string $type, ?ActorRef $actor, array $data = []): void
    {
        $this->db()->table($this->tables->table('order_events'))->insert([
            'order_id' => $order->value,
            'type' => $type,
            'actor_ref' => $actor === null ? null : (string) $actor,
            'data' => json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => Clock::now(),
        ]);
    }

    /** @return list<OrderEventView> oldest first */
    public function history(OrderId $order): array
    {
        $events = [];

        foreach ($this->db()->table($this->tables->table('order_events'))->where('order_id', $order->value)->orderBy('id')->get() as $row) {
            $actor = Rows::nullableString($row, 'actor_ref');

            $events[] = new OrderEventView(
                Rows::int($row, 'id'),
                $order,
                Rows::string($row, 'type'),
                $actor === null ? null : ActorRef::fromString($actor),
                self::data(Rows::string($row, 'data')),
                Rows::date($row, 'created_at'),
            );
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private static function data(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Stored order event data is not valid JSON.', previous: $e);
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new LogicException('Stored order event data is not a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

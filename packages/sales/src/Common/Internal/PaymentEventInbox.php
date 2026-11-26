<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Money\Money;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * PAY-01: the inbox of provider events (`payment_events`), one row per `(gateway, external_event_id)`, shared by
 * payments and refunds. `claim` runs under the lock of the order the event is about, so a concurrent delivery of the
 * same event waits for the first one and then finds it recorded. A transition is applied only after a claim.
 */
#[Internal]
final readonly class PaymentEventInbox
{
    public const string APPLIED = 'applied';

    public const string IGNORED = 'ignored';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /**
     * Records the event as `ignored` until `settle` says otherwise.
     *
     * @param  string  $operation  the operation named by a conflict, e.g. `sales.payment_event`
     * @param  string  $fingerprint  64 hex digits of what the event says (`Fingerprint::of`)
     * @param  array<string, scalar>  $payload
     * @return string|null null when this call recorded the event; the stored outcome when it was recorded before
     *
     * @throws IdempotencyConflict the event id was recorded with another fingerprint
     */
    public function claim(
        string $operation,
        string $gateway,
        string $externalEventId,
        string $type,
        string $fingerprint,
        ?string $paymentId,
        ?string $refundId,
        ?Money $amount,
        ?DateTimeImmutable $occurredAt,
        array $payload,
    ): ?string {
        $db = $this->db();
        $table = $this->tables->table('payment_events');
        $wrapped = $db->table($table)->getGrammar()->wrapTable($table);

        // ON CONFLICT never raises, so the caller's transaction is not poisoned.
        $inserted = $db->select(
            "insert into {$wrapped} (gateway, external_event_id, type, fingerprint, payment_id, refund_id, outcome, currency, amount_minor, payload, occurred_at, created_at)"
            .' values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) on conflict (gateway, external_event_id) do nothing returning id',
            [
                $gateway,
                $externalEventId,
                $type,
                $fingerprint,
                $paymentId,
                $refundId,
                self::IGNORED,
                $amount?->currency->code,
                $amount?->amountMinor,
                json_encode((object) $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $occurredAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
                PgTimestamp::now(),
            ],
        );

        if ($inserted !== []) {
            return null;
        }

        $stored = $db->table($table)->where('gateway', $gateway)->where('external_event_id', $externalEventId)->first()
            ?? throw new RuntimeException("Event '{$gateway}/{$externalEventId}' conflicted but cannot be read.");

        return Rows::string($stored, 'fingerprint') === $fingerprint
            ? Rows::string($stored, 'outcome')
            : throw new IdempotencyConflict($operation, $externalEventId);
    }

    /** Marks a claimed event as having changed its payment or refund. */
    public function applied(string $gateway, string $externalEventId): void
    {
        $this->db()->table($this->tables->table('payment_events'))
            ->where('gateway', $gateway)
            ->where('external_event_id', $externalEventId)
            ->update(['outcome' => self::APPLIED]);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

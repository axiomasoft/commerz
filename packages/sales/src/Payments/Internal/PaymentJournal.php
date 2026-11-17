<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\CommerzException;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Sales\Common\Internal\Clock;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Orders\Enums\OrderState;
use Commerz\Sales\Payments\Data\InitiatePayment;
use Commerz\Sales\Payments\Data\ListPayments;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Data\PaymentView;
use Commerz\Sales\Payments\Enums\PaymentStatus;
use Commerz\Sales\Payments\Events\PaymentStatusChanged;
use Commerz\Sales\Payments\Exceptions\OrderNotPayable;
use Commerz\Sales\Payments\Exceptions\PaymentExceedsBalance;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;
use Throwable;

/**
 * The only writer of `payments` (D33). A row is one attempt and its lease: `open` writes it `initiating` under the
 * lock of the order, after checking the balance; `lease` hands a reconcile the next attempt; `finish` records the
 * gateway's answer only while the row still carries the attempt it was asked under, so a process whose lease was taken
 * over never overwrites the new owner. A provider event that moved the payment meanwhile does not fence the answer
 * off: `PaymentTransitions` never moves a payment back, and a captured answer must never be lost. Every change of status is
 * journalled on the order, announced after commit and followed by `OrderStatus::refresh`.
 */
#[Internal]
final readonly class PaymentJournal
{
    /** Statuses whose money no longer counts against the balance of the order. */
    private const array SETTLED = ['failed', 'cancelled'];

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private OrderLocks $locks,
        private OrderJournal $journal,
        private OrderStatus $status,
        private CommittedEvents $events,
    ) {}

    /**
     * Tx A of an initiate, under the lock of the order: the balance is the amount due less every attempt that may
     * still bring money (captured ones by what they took), and the new attempt must fit in it.
     *
     * @throws OrderNotPayable
     * @throws InvalidCommand sales.currency_mismatch
     * @throws PaymentExceedsBalance
     */
    public function open(OrderRow $order, InitiatePayment $command, string $fingerprint): PaymentRow
    {
        if ($order->state !== OrderState::Placed) {
            throw new OrderNotPayable($order->id->value, $order->state->value);
        }

        if ($command->amount !== null && ! $command->amount->currency->equals($order->currency)) {
            throw new InvalidCommand('amount', "must be in {$order->currency->code}, the currency of the order", 'sales.currency_mismatch');
        }

        $db = $this->db();
        $due = $db->table($this->tables->table('orders'))->where('id', $order->id->value)->value('amount_due_minor');
        $committed = $db->table($this->tables->table('payments'))
            ->where('order_id', $order->id->value)
            ->whereNotIn('status', self::SETTLED)
            ->selectRaw("coalesce(sum(case when status = 'captured' then captured_minor else amount_minor end), 0)::bigint as committed")
            ->value('committed');
        $balance = (is_int($due) ? $due : 0) - (is_int($committed) ? $committed : 0);
        $amount = $command->amount ?? Money::ofMinor(max(0, $balance), $order->currency);

        if (! $amount->isPositive() || $amount->amountMinor > $balance) {
            throw new PaymentExceedsBalance($order->id->value, $amount, Money::ofMinor(max(0, $balance), $order->currency));
        }

        $id = Uuid7::generate();
        $now = Clock::now();

        $db->table($this->tables->table('payments'))->insert([
            'id' => $id,
            'order_id' => $order->id->value,
            'gateway' => $command->gateway,
            'reference' => 'payment:'.$id,
            'status' => PaymentStatus::Initiating->value,
            'currency' => $order->currency->code,
            'amount_minor' => $amount->amountMinor,
            'meta' => self::json($command->meta),
            'fingerprint' => $fingerprint,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->journal->append($order->id, 'payment_initiated', $command->actor, [
            'payment_id' => $id,
            'gateway' => $command->gateway,
            'amount_minor' => $amount->amountMinor,
            'currency' => $order->currency->code,
        ]);

        return $this->get($id);
    }

    /**
     * The customer of the order and the e-mail a gateway accepts (an address longer than 191 bytes is left out).
     *
     * @return array{CustomerRef|null, string|null}
     */
    public function customer(OrderRow $order): array
    {
        $row = $this->db()->table($this->tables->table('orders'))->where('id', $order->id->value)->first(['customer_ref', 'customer_email']);
        $ref = $row === null ? null : Rows::nullableString($row, 'customer_ref');
        $email = $row === null ? null : Rows::nullableString($row, 'customer_email');

        return [
            $ref === null ? null : CustomerRef::fromString($ref),
            $email !== null && $email !== '' && strlen($email) <= 191 ? $email : null,
        ];
    }

    /** @throws PaymentNotFound */
    public function get(string $id): PaymentRow
    {
        $row = $this->db()->table($this->tables->table('payments'))->where('id', $id)->first();

        return PaymentRow::fromRow($row ?? throw new PaymentNotFound($id));
    }

    /** The payment `for update`; the caller holds the lock of its order. @throws PaymentNotFound */
    public function lock(PaymentId $id): PaymentRow
    {
        $row = $this->db()->table($this->tables->table('payments'))->where('id', $id->value)->lockForUpdate()->first();

        return PaymentRow::fromRow($row ?? throw new PaymentNotFound($id->value));
    }

    /** The payment of the gateway with our reference or, failing that, the provider's id; read without a lock. */
    public function locate(string $gateway, ?string $reference, ?string $externalId): ?PaymentRow
    {
        $table = $this->db()->table($this->tables->table('payments'))->where('gateway', $gateway);
        $row = $reference === null ? null : (clone $table)->where('reference', $reference)->first();
        $row ??= $externalId === null ? null : (clone $table)->where('external_id', $externalId)->first();

        return $row === null ? null : PaymentRow::fromRow($row);
    }

    /** Whether another payment of the gateway already carries the provider's id. */
    public function externalIdTaken(PaymentRow $payment, string $externalId): bool
    {
        return $this->db()->table($this->tables->table('payments'))
            ->where('gateway', $payment->gateway)
            ->where('external_id', $externalId)
            ->where('id', '<>', $payment->id->value)
            ->exists();
    }

    /** Takes the next attempt of a payment locked by the caller: a new lease from now. */
    public function lease(PaymentRow $payment): PaymentRow
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('payments'))->where('id', $payment->id->value)->update([
            'attempts' => $payment->attempts + 1,
            'claimed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get($payment->id->value);
    }

    /**
     * Tx B: records an answer of the gateway when the payment still carries `$attempt`; otherwise the lease was taken
     * over and nothing is written. `$write` applies the answer to the payment as it is now, through the transitions.
     *
     * @param  Closure(OrderRow, PaymentRow): PaymentRow  $write  the change, given the locked order and payment
     * @return PaymentRow the payment as it is afterwards
     */
    public function finish(PaymentRow $claimed, int $attempt, Closure $write): PaymentRow
    {
        return $this->transactions->run(function () use ($claimed, $attempt, $write): PaymentRow {
            $order = $this->locks->lock($claimed->order);
            $current = $this->lock($claimed->id);

            if ($current->attempts !== $attempt) {
                return $current;
            }

            return $write($order, $current);
        });
    }

    /**
     * Writes the payment `$next` (from `PaymentTransitions` or a status of the protocol) and `$columns`, under the
     * locks of the order and the payment. A change of status is journalled and announced, and the order refreshed.
     *
     * @param  array<string, scalar|null>  $columns  e.g. `external_id`, `meta`, the error of the attempt
     */
    public function write(OrderRow $order, PaymentRow $current, PaymentRow $next, array $columns = [], ?ActorRef $actor = null): PaymentRow
    {
        $values = array_filter([
            'status' => $next->status->value,
            'authorized_minor' => $next->authorized,
            'captured_minor' => $next->captured,
            'action_url' => $next->actionUrl,
        ], static fn (int|string|null $value, string $column): bool => match ($column) {
            'status' => $value !== $current->status->value,
            'authorized_minor' => $value !== $current->authorized,
            'captured_minor' => $value !== $current->captured,
            default => $value !== $current->actionUrl,
        }, ARRAY_FILTER_USE_BOTH);

        $changed = $next->status !== $current->status;

        // An error explains a failed or unknown attempt only; it goes when the payment moves on.
        if ($changed && ! in_array($next->status, [PaymentStatus::Failed, PaymentStatus::Unknown], true) && $current->errorCode !== null) {
            $values += ['error_code' => null, 'error_message' => null];
        }

        $values = $columns + $values;

        if ($values === []) {
            return $current;
        }

        $this->db()->table($this->tables->table('payments'))->where('id', $current->id->value)->update($values + ['updated_at' => Clock::now()]);

        if ($changed) {
            $this->journal->append($order->id, 'payment_status_changed', $actor, [
                'payment_id' => $current->id->value,
                'from' => $current->status->value,
                'to' => $next->status->value,
            ]);
            $this->events->dispatch(new PaymentStatusChanged($current->id, $order->id, $next->status));
        }

        $this->status->refresh($order, actor: $actor);

        return $this->get($current->id->value);
    }

    /** @return Page<PaymentView> newest first, by `(created_at, id)` */
    public function page(ListPayments $query): Page
    {
        $builder = $this->db()->table($this->tables->table('payments'))
            ->select('*')
            ->selectRaw('created_at::text as created_position')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($query->limit + 1);

        if ($query->order !== null) {
            $builder->where('order_id', $query->order->value);
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            self::after($builder, $query->after);
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return new Page(
            array_map(static fn (object $row): PaymentView => PaymentRow::fromRow($row)->view(), $rows),
            $more && $last !== null ? Cursor::after(Rows::string($last, 'created_position').'|'.Rows::string($last, 'id')) : null,
        );
    }

    /**
     * The error columns of an attempt: a gateway's text as PostgreSQL text, valid UTF-8 without NUL.
     *
     * @return array{error_code: string, error_message: string}
     */
    public static function error(Throwable|string $error, string $message = ''): array
    {
        [$code, $message] = match (true) {
            is_string($error) => [$error, $message],
            $error instanceof CommerzException => [$error->code(), $error->getMessage()],
            default => [$error::class, $error->getMessage()],
        };

        return [
            'error_code' => mb_substr(self::text($code), 0, 128),
            'error_message' => mb_substr(self::text($message), 0, 2000),
        ];
    }

    /** @param  array<string, scalar>  $meta */
    public static function json(array $meta): string
    {
        return json_encode((object) $meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function text(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    /** The keyset is (created_at, id), newest first, in PostgreSQL's own text of the timestamp. */
    private static function after(Builder $builder, Cursor $cursor): void
    {
        $parts = explode('|', $cursor->position());

        if (count($parts) !== 2
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2}){0,2}\z/', $parts[0]) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $parts[1]) !== 1) {
            throw new InvalidCommand('after', 'is not a valid cursor', 'common.invalid_cursor');
        }

        $builder->whereRaw('(created_at, id) < (?::timestamptz, ?::uuid)', $parts);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

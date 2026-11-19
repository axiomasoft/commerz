<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Internal;

use Closure;
use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\CommerzException;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\ActorRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Ports\Promotions\Contracts\PromotionUsage;
use Commerz\Ports\Promotions\Data\RestorePromotionUsage;
use Commerz\Ports\Promotions\Enums\RestoreReason;
use Commerz\Sales\Common\Internal\Clock;
use Commerz\Sales\Common\Internal\OrderJournal;
use Commerz\Sales\Common\Internal\OrderLocks;
use Commerz\Sales\Common\Internal\OrderRow;
use Commerz\Sales\Common\Internal\OrderStatus;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Payments\Data\PaymentId;
use Commerz\Sales\Payments\Exceptions\PaymentNotFound;
use Commerz\Sales\Refunds\Data\IssueRefund;
use Commerz\Sales\Refunds\Data\ListRefunds;
use Commerz\Sales\Refunds\Data\RefundId;
use Commerz\Sales\Refunds\Data\RefundView;
use Commerz\Sales\Refunds\Enums\RefundStatus;
use Commerz\Sales\Refunds\Events\RefundStatusChanged;
use Commerz\Sales\Refunds\Exceptions\RefundExceedsBalance;
use Commerz\Sales\Refunds\Exceptions\RefundNotFound;
use Commerz\Sales\Returns\Exceptions\ReturnNotFound;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

/**
 * The only writer of `refunds` (D33), and of the refunded sum of a payment. A row is one attempt and its lease: `open`
 * writes it `initiating` under the locks of the order and the payment, after checking what the payment still holds;
 * `lease` hands a reconcile the next attempt; `finish` records an answer only while the row carries the attempt it
 * was asked under. Every write follows sales-design#locks: the order, then the promotion usage given back when the
 * refund leaves the order fully refunded, then the payment, then the refund.
 */
#[Internal]
final readonly class RefundJournal
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private OrderLocks $locks,
        private OrderJournal $journal,
        private OrderStatus $status,
        private PromotionUsage $usage,
        private Outbox $outbox,
        private CommittedEvents $events,
    ) {}

    /**
     * Tx A, under the lock of the order: the payment to refund, locked. A named payment must belong to the order;
     * otherwise the oldest captured payment that still holds the amount. What a payment holds is what it captured less
     * every refund of it that has not failed, an unknown one included.
     *
     * @throws InvalidCommand sales.currency_mismatch
     * @throws PaymentNotFound
     * @throws RefundExceedsBalance
     */
    public function payment(OrderRow $order, IssueRefund $command): RefundablePayment
    {
        if (! $command->amount->currency->equals($order->currency)) {
            throw new InvalidCommand('amount', "must be in {$order->currency->code}, the currency of the order", 'sales.currency_mismatch');
        }

        $refunds = $this->tables->table('refunds');
        $builder = $this->db()->table($this->tables->table('payments'), 'p')
            ->where('p.order_id', $order->id->value)
            ->select(['p.id', 'p.gateway', 'p.reference', 'p.external_id'])
            ->selectSub(
                $this->db()->table($refunds, 'r')->whereColumn('r.payment_id', 'p.id')->where('r.status', '<>', RefundStatus::Failed->value)
                    ->selectRaw('p.captured_minor - coalesce(sum(r.amount_minor), 0)::bigint'),
                'refundable',
            )
            ->orderBy('p.created_at')
            ->orderBy('p.id')
            ->lockForUpdate();

        if ($command->payment !== null) {
            $row = $builder->where('p.id', $command->payment->value)->first() ?? throw new PaymentNotFound($command->payment->value);
            $candidates = [self::refundable($row)];
        } else {
            $candidates = array_map(self::refundable(...), $builder->where('p.captured_minor', '>', 0)->get()->all());
        }

        foreach ($candidates as $candidate) {
            if ($candidate->refundable >= $command->amount->amountMinor) {
                return $candidate;
            }
        }

        $most = max([0, ...array_map(static fn (RefundablePayment $payment): int => $payment->refundable, $candidates)]);

        throw new RefundExceedsBalance($order->id->value, $command->amount, Money::ofMinor($most, $order->currency));
    }

    /**
     * Tx A: writes the attempt `initiating` against the payment the caller locked.
     *
     * @throws ReturnNotFound the return is not one of the order
     */
    public function open(OrderRow $order, RefundablePayment $payment, IssueRefund $command, string $fingerprint): RefundRow
    {
        $db = $this->db();

        if ($command->return !== null
            && ! $db->table($this->tables->table('returns'))->where('id', $command->return->value)->where('order_id', $order->id->value)->exists()) {
            throw new ReturnNotFound($command->return->value);
        }

        $id = Uuid7::generate();
        $now = Clock::now();

        $db->table($this->tables->table('refunds'))->insert([
            'id' => $id,
            'order_id' => $order->id->value,
            'payment_id' => $payment->id->value,
            'return_id' => $command->return?->value,
            'gateway' => $payment->gateway,
            'reference' => 'refund:'.$id,
            'status' => RefundStatus::Initiating->value,
            'currency' => $order->currency->code,
            'amount_minor' => $command->amount->amountMinor,
            'reason' => $command->reason,
            'fingerprint' => $fingerprint,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->journal->append($order->id, 'refund_issued', $command->actor, [
            'refund_id' => $id,
            'payment_id' => $payment->id->value,
            'return_id' => $command->return?->value,
            'amount_minor' => $command->amount->amountMinor,
            'currency' => $order->currency->code,
        ]);

        return $this->get($id);
    }

    /** @throws RefundNotFound */
    public function get(RefundId|string $id): RefundRow
    {
        $id = $id instanceof RefundId ? $id->value : $id;
        $row = $this->db()->table($this->tables->table('refunds'))->where('id', $id)->first();

        return RefundRow::fromRow($row ?? throw new RefundNotFound($id));
    }

    /** The refund of the gateway with our reference or, failing that, the provider's id; read without a lock. */
    public function locate(string $gateway, ?string $reference, ?string $externalId): ?RefundRow
    {
        $table = $this->db()->table($this->tables->table('refunds'))->where('gateway', $gateway);
        $row = $reference === null ? null : (clone $table)->where('reference', $reference)->first();
        $row ??= $externalId === null ? null : (clone $table)->where('external_id', $externalId)->first();

        return $row === null ? null : RefundRow::fromRow($row);
    }

    /** Whether another refund of the gateway already carries the provider's id. */
    public function externalIdTaken(RefundRow $refund, string $externalId): bool
    {
        return $this->db()->table($this->tables->table('refunds'))
            ->where('gateway', $refund->gateway)
            ->where('external_id', $externalId)
            ->where('id', '<>', $refund->id->value)
            ->exists();
    }

    /** Takes the next attempt of a refund whose order the caller locked: a new lease from now. */
    public function lease(RefundRow $refund): RefundRow
    {
        $now = Clock::now();

        $this->db()->table($this->tables->table('refunds'))->where('id', $refund->id->value)->update([
            'attempts' => $refund->attempts + 1,
            'claimed_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get($refund->id);
    }

    /**
     * Tx B: records an answer of the gateway when the refund still carries `$attempt`; otherwise the lease was taken
     * over and nothing is written. The status is not fenced: `transition` never moves a refund back, and a provider
     * event that settled it first leaves the answer nothing to change. `$write` gets the refund as read under the lock
     * of the order, which every writer of refunds holds.
     *
     * @param  Closure(OrderRow, RefundRow): RefundRow  $write
     */
    public function finish(RefundRow $claimed, int $attempt, Closure $write): RefundRow
    {
        return $this->transactions->run(function () use ($claimed, $attempt, $write): RefundRow {
            $order = $this->locks->lock($claimed->order);
            $current = $this->get($claimed->id);

            return $current->attempts === $attempt ? $write($order, $current) : $current;
        });
    }

    /**
     * Moves the refund to what `$observed` leads to (`transition`) and writes `$columns`, under the lock of the order the caller
     * took and `$current` read under it. A refund that succeeds and leaves the order fully refunded first gives its
     * promotion usage back, before the payment is locked (promotions → payments, sales-design#locks); then the payment
     * and the refund are locked, the payment's refunded sum is recomputed, and the order refreshed.
     *
     * @param  array<string, scalar|null>  $columns  e.g. `external_id`, the error of the attempt
     */
    public function write(OrderRow $order, RefundRow $current, ?RefundStatus $observed, array $columns = [], ?ActorRef $actor = null): RefundRow
    {
        $next = $observed === null ? null : self::transition($current->status, $observed);

        if ($current->status === RefundStatus::Failed && $next === RefundStatus::Succeeded && ! $this->stillHeld($current)) {
            // The failure freed the amount and another refund took it: the provider gave the money back twice. The
            // books cannot hold more refunded than captured, so the refund stays failed and the order records why.
            $this->journal->append($order->id, 'refund_over_refunded', $actor, [
                'refund_id' => $current->id->value,
                'payment_id' => $current->payment->value,
                'amount_minor' => $current->amount,
                'currency' => $current->currency->code,
            ]);
            $next = null;
        }

        if ($next === null) {
            // The error of an answer that changes nothing must not be pinned to a refund it does not explain.
            unset($columns['error_code'], $columns['error_message']);
        }

        if ($next === RefundStatus::Succeeded && $this->refundsOrderFully($order, $current)) {
            // Whether usage comes back is the policy of the promotions (`restore_on`, D13), not of sales.
            $this->usage->restore(new RestorePromotionUsage('order:'.$order->id->value, RestoreReason::Refunded));
        }

        $db = $this->db();
        $db->table($this->tables->table('payments'))->where('id', $current->payment->value)->lockForUpdate()->first();
        $db->table($this->tables->table('refunds'))->where('id', $current->id->value)->lockForUpdate()->first();

        $values = $columns;

        if ($next !== null) {
            $values['status'] = $next->value;

            // An error explains a failed or unknown attempt only; it goes when the refund moves on.
            if (! in_array($next, [RefundStatus::Failed, RefundStatus::Unknown], true) && $current->errorCode !== null) {
                $values += ['error_code' => null, 'error_message' => null];
            }
        }

        if ($values === []) {
            return $current;
        }

        $db->table($this->tables->table('refunds'))->where('id', $current->id->value)->update($values + ['updated_at' => Clock::now()]);

        if ($next !== null) {
            $this->settled($order, $current, $next, $actor);
        }

        return $this->get($current->id);
    }

    /**
     * The status a refund moves to when `$observed` is seen, or null when it stays: `succeeded` is final, and so is
     * `failed` except for a later success — the money went back, which is stronger than a failure (an event can
     * overtake the gateway's own answer, as for payments); `pending` follows only `initiating` or `unknown`; `unknown`
     * only an `initiating` attempt whose answer was lost.
     */
    public static function transition(RefundStatus $current, RefundStatus $observed): ?RefundStatus
    {
        return match (true) {
            $current === RefundStatus::Failed && $observed === RefundStatus::Succeeded => $observed,
            $current->isFinal(), $current === $observed => null,
            $observed->isFinal() => $observed,
            $observed === RefundStatus::Pending => $current === RefundStatus::Initiating || $current === RefundStatus::Unknown ? $observed : null,
            $observed === RefundStatus::Unknown => $current === RefundStatus::Initiating ? $observed : null,
            default => null,
        };
    }

    /** @return Page<RefundView> newest first, by `(created_at, id)` */
    public function page(ListRefunds $query): Page
    {
        $builder = $this->db()->table($this->tables->table('refunds'))
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
            array_map(static fn (object $row): RefundView => RefundRow::fromRow($row)->view(), $rows),
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

    /** A change of status: journalled, announced; a success also counts on the payment and in the outbox. */
    private function settled(OrderRow $order, RefundRow $current, RefundStatus $next, ?ActorRef $actor): void
    {
        $db = $this->db();

        if ($next === RefundStatus::Succeeded) {
            $db->table($this->tables->table('payments'))->where('id', $current->payment->value)->update([
                'refunded_minor' => $this->succeeded('payment_id', $current->payment->value),
                'updated_at' => Clock::now(),
            ]);
        }

        $this->journal->append($order->id, 'refund_status_changed', $actor, [
            'refund_id' => $current->id->value,
            'from' => $current->status->value,
            'to' => $next->value,
        ]);
        $this->status->refresh($order, actor: $actor);

        if ($next === RefundStatus::Succeeded) {
            $this->outbox->record(IntegrationEvent::of('sales.refund_succeeded', 'order:'.$order->id->value, [
                'order_id' => $order->id->value,
                'number' => $order->number,
                'refund_id' => $current->id->value,
                'payment_id' => $current->payment->value,
                'return_id' => $current->return?->value,
                'amount_minor' => $current->amount,
                'currency' => $current->currency->code,
            ]));
        }

        $this->events->dispatch(new RefundStatusChanged($current->id, $order->id, $next));
    }

    /** Whether the payment still holds the amount of a failed refund: what it captured less the refunds not failed. */
    private function stillHeld(RefundRow $refund): bool
    {
        $row = $this->db()->table($this->tables->table('payments'), 'p')
            ->where('p.id', $refund->payment->value)
            ->selectSub(
                $this->db()->table($this->tables->table('refunds'), 'r')->whereColumn('r.payment_id', 'p.id')->where('r.status', '<>', RefundStatus::Failed->value)
                    ->selectRaw('p.captured_minor - coalesce(sum(r.amount_minor), 0)::bigint'),
                'refundable',
            )
            ->first();

        return $row !== null && Rows::int($row, 'refundable') >= $refund->amount;
    }

    /** Whether the fully paid order's succeeded refunds and this one together give back all it captured. */
    private function refundsOrderFully(OrderRow $order, RefundRow $refund): bool
    {
        $payments = $this->db()->table($this->tables->table('payments'))->where('order_id', $order->id->value)
            ->selectRaw('coalesce(sum(captured_minor), 0)::bigint as captured')
            ->first();
        $captured = $payments === null ? 0 : Rows::int($payments, 'captured');
        $row = $this->db()->table($this->tables->table('orders'))->where('id', $order->id->value)->first(['amount_due_minor']);
        $amountDue = Rows::int($row ?? throw new LogicException('Locked order disappeared.'), 'amount_due_minor');

        return $captured > 0 && $captured >= $amountDue
            && $this->succeeded('order_id', $order->id->value) + $refund->amount >= $captured;
    }

    /** The money of the succeeded refunds of one payment or order (`$column` = `payment_id` or `order_id`). */
    private function succeeded(string $column, string $id): int
    {
        $row = $this->db()->table($this->tables->table('refunds'))
            ->where($column, $id)
            ->where('status', RefundStatus::Succeeded->value)
            ->selectRaw('coalesce(sum(amount_minor), 0)::bigint as refunded')
            ->first();

        return $row === null ? 0 : Rows::int($row, 'refunded');
    }

    private static function refundable(object $row): RefundablePayment
    {
        return new RefundablePayment(
            new PaymentId(Rows::string($row, 'id')),
            Rows::string($row, 'gateway'),
            Rows::string($row, 'reference'),
            Rows::nullableString($row, 'external_id'),
            max(0, Rows::int($row, 'refundable')),
        );
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

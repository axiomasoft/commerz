<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\IdempotencyKey;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\OperationReceipts;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Operations\Fingerprint;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Promotions\Common\Data\PromotionCodes;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Common\Internal\Clock;
use Commerz\Promotions\Common\Internal\Rows;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Coupons\Contracts\Coupons;
use Commerz\Promotions\Coupons\Data\CouponBatchId;
use Commerz\Promotions\Coupons\Data\CouponBatchView;
use Commerz\Promotions\Coupons\Data\CouponId;
use Commerz\Promotions\Coupons\Data\CouponView;
use Commerz\Promotions\Coupons\Data\CreateCoupon;
use Commerz\Promotions\Coupons\Data\DisableCoupon;
use Commerz\Promotions\Coupons\Data\EnableCoupon;
use Commerz\Promotions\Coupons\Data\GenerateCoupons;
use Commerz\Promotions\Coupons\Data\ListCoupons;
use Commerz\Promotions\Coupons\Data\SetCouponLimit;
use Commerz\Promotions\Coupons\Enums\CouponStatus;
use Commerz\Promotions\Coupons\Events\CouponsGenerated;
use Commerz\Promotions\Coupons\Exceptions\CodeSpaceExhausted;
use Commerz\Promotions\Coupons\Exceptions\CouponNotFound;
use Commerz\Promotions\Coupons\Exceptions\NotACouponPromotion;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;
use UnexpectedValueException;

/**
 * Lock order: coupons `for update` when a coupon changes (disable, enable, limit); the promotion `for key share` when
 * coupons are added to it, so that archiving it (`for update`) waits for them (D27). Code creation takes the shared
 * transaction advisory lock before a promotion or receipt: retries in later generation rounds must not cycle over
 * codes inserted by another uncommitted batch. A change of a coupon takes no promotion row or code-creation lock.
 */
#[Internal]
final readonly class DatabaseCoupons implements Coupons
{
    private const string RECEIPT_SCOPE = 'promotions.generate_coupons';

    private const int CHUNK = 1000;

    private const int ROUNDS = 10;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private PromotionsOptions $promotions,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private OperationReceipts $receipts,
    ) {}

    public function create(CreateCoupon $command): CouponView
    {
        return $this->transactions->run(function () use ($command): CouponView {
            $this->lockCodes();
            $this->lockCouponPromotion($command->promotion);

            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('coupons'))->insert([
                    'id' => $id,
                    'promotion_id' => $command->promotion->value,
                    'batch_id' => null,
                    'code' => $command->code,
                    'status' => CouponStatus::Active->value,
                    'usage_limit' => $command->usageLimit,
                    'used' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Coupon', $command->code);
            }

            return $this->find(new CouponId($id));
        });
    }

    public function generate(GenerateCoupons $command): CouponBatchView
    {
        if ($command->count > $this->promotions->maxGenerate) {
            throw new InvalidCommand('count', "must be at most {$this->promotions->maxGenerate}", 'promotions.invalid_count');
        }

        $pattern = CouponPattern::parse($command->pattern, $this->promotions->couponAlphabet);

        if (! $pattern->holds($command->count)) {
            throw new InvalidCommand('pattern', "'{$pattern->pattern}' has too few codes for {$command->count} coupons", 'promotions.pattern_too_small');
        }

        return $this->transactions->run(function () use ($command, $pattern): CouponBatchView {
            $this->lockCodes();
            $work = fn (): CouponBatchView => $this->generateBatch($command, $pattern);

            if (! $command->idempotencyKey instanceof IdempotencyKey) {
                return $work();
            }

            return $this->receipts->once(
                self::RECEIPT_SCOPE,
                $command->idempotencyKey,
                Fingerprint::of([
                    'promotion' => $command->promotion->value,
                    'count' => $command->count,
                    'pattern' => $pattern->pattern,
                    'usageLimit' => $command->usageLimit,
                ]),
                $work,
                static fn (CouponBatchView $batch): array => [
                    'id' => $batch->id->value,
                    'promotion' => $batch->promotion->value,
                    'pattern' => $batch->pattern,
                    'count' => $batch->count,
                    'usageLimit' => $batch->usageLimit,
                    'createdAt' => $batch->createdAt->format('Y-m-d H:i:s.uP'),
                ],
                static fn (array $stored): CouponBatchView => new CouponBatchView(
                    new CouponBatchId(self::string($stored, 'id')),
                    new PromotionId(self::string($stored, 'promotion')),
                    self::string($stored, 'pattern'),
                    is_int($stored['count'] ?? null) ? $stored['count'] : 0,
                    is_int($stored['usageLimit'] ?? null) ? $stored['usageLimit'] : null,
                    new DateTimeImmutable(self::string($stored, 'createdAt')),
                ),
            );
        });
    }

    public function disable(DisableCoupon $command): CouponView
    {
        return $this->transition($command->coupon, CouponStatus::Disabled);
    }

    public function enable(EnableCoupon $command): CouponView
    {
        return $this->transition($command->coupon, CouponStatus::Active);
    }

    public function setLimit(SetCouponLimit $command): CouponView
    {
        return $this->transactions->run(function () use ($command): CouponView {
            $row = $this->lock($command->coupon);

            if ($command->usageLimit !== Rows::nullableInt($row, 'usage_limit')) {
                // The counter is kept only while a limit is set, so a limit set now starts from the orders that hold the coupon.
                $this->db()->table($this->tables->table('coupons'))->where('id', $command->coupon->value)->update([
                    'usage_limit' => $command->usageLimit,
                    'used' => $command->usageLimit === null ? 0 : ($this->consumed([$command->coupon->value])[$command->coupon->value] ?? 0),
                    'updated_at' => Clock::now(),
                ]);
            }

            return $this->find($command->coupon);
        });
    }

    public function find(CouponId $id): CouponView
    {
        $row = $this->db()->table($this->tables->table('coupons'))->where('id', $id->value)->first();

        return $this->views([$row ?? throw new CouponNotFound($id->value)])[0];
    }

    public function findByCode(string $code): CouponView
    {
        try {
            $canonical = PromotionCodes::couponCode('code', $code);
        } catch (InvalidCommand) {
            throw new CouponNotFound($code); // no coupon has such a code
        }

        $row = $this->db()->table($this->tables->table('coupons'))->where('code', $canonical)->first();

        return $this->views([$row ?? throw new CouponNotFound($canonical)])[0];
    }

    public function list(ListCoupons $query): Page
    {
        $builder = $this->db()->table($this->tables->table('coupons'))
            ->where('promotion_id', $query->promotion->value)
            ->orderBy('code')
            ->limit($query->first + 1);

        if ($query->batch instanceof CouponBatchId) {
            $builder->where('batch_id', $query->batch->value);
        }

        if ($query->status instanceof CouponStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function generateBatch(GenerateCoupons $command, CouponPattern $pattern): CouponBatchView
    {
        $this->lockCouponPromotion($command->promotion);

        $db = $this->db();
        $batch = Uuid7::generate();
        $now = Clock::now();

        $db->table($this->tables->table('coupon_batches'))->insert([
            'id' => $batch,
            'promotion_id' => $command->promotion->value,
            'pattern' => $pattern->pattern,
            'count' => $command->count,
            'usage_limit' => $command->usageLimit,
            'created_at' => $now,
        ]);

        $missing = $command->count;

        for ($round = 0; $round < self::ROUNDS && $missing > 0; $round++) {
            $missing -= $this->insertCodes($pattern, $missing, $command, $batch);
        }

        if ($missing > 0) {
            throw new CodeSpaceExhausted($pattern->pattern, $missing);
        }

        $this->events->dispatch(new CouponsGenerated($command->promotion, new CouponBatchId($batch), $command->count));

        return new CouponBatchView(new CouponBatchId($batch), $command->promotion, $pattern->pattern, $command->count, $command->usageLimit, new DateTimeImmutable($now));
    }

    /**
     * One round: `$wanted` fresh random codes, no repeats among them, inserted in chunks. A code another batch (or a
     * concurrent one) already holds is skipped by `on conflict do nothing`; the caller asks again for the rest. The
     * codes go in sorted order within a round. The code-creation lock covers all rounds: sorting a new round alone
     * cannot order it against codes held from an earlier round of another batch.
     *
     * @return int how many of the codes were new
     */
    private function insertCodes(CouponPattern $pattern, int $wanted, GenerateCoupons $command, string $batch): int
    {
        $codes = [];

        while (count($codes) < $wanted) {
            $codes[$pattern->generate()] = true;
        }

        $codes = array_keys($codes);
        sort($codes, SORT_STRING);

        $db = $this->db();
        $name = $this->tables->table('coupons');
        $table = $db->table($name)->getGrammar()->wrapTable($name);
        $now = Clock::now();
        $inserted = 0;

        foreach (array_chunk($codes, self::CHUNK) as $chunk) {
            $bindings = [];
            $rows = [];

            foreach ($chunk as $code) {
                $rows[] = '(?, ?, ?, ?, ?, 0, ?, ?)';
                $bindings[] = Uuid7::generate();
                $bindings[] = $command->promotion->value;
                $bindings[] = $batch;
                $bindings[] = $code;
                $bindings[] = $command->usageLimit;
                $bindings[] = $now;
                $bindings[] = $now;
            }

            $inserted += count($db->select(
                "insert into {$table} (id, promotion_id, batch_id, code, usage_limit, used, created_at, updated_at) values "
                .implode(', ', $rows).' on conflict (code) do nothing returning id',
                $bindings,
            ));
        }

        return $inserted;
    }

    /** Moves to `$target`; already there is no change. */
    private function transition(CouponId $id, CouponStatus $target): CouponView
    {
        return $this->transactions->run(function () use ($id, $target): CouponView {
            $row = $this->lock($id);

            if (Rows::string($row, 'status') !== $target->value) {
                $this->db()->table($this->tables->table('coupons'))->where('id', $id->value)->update([
                    'status' => $target->value,
                    'updated_at' => Clock::now(),
                ]);
            }

            return $this->find($id);
        });
    }

    /**
     * Takes the promotion `for key share` and checks that coupons may be added to it.
     *
     * @throws PromotionNotFound|PromotionArchived|NotACouponPromotion
     */
    private function lockCouponPromotion(PromotionId $id): void
    {
        $row = $this->db()->table($this->tables->table('promotions'))
            ->where('id', $id->value)
            ->lock('for key share')
            ->first(['code', 'status', 'activator']);

        if ($row === null) {
            throw new PromotionNotFound($id->value);
        }

        if (Rows::string($row, 'status') === PromotionStatus::Archived->value) {
            throw new PromotionArchived(Rows::string($row, 'code'));
        }

        if (Rows::string($row, 'activator') !== 'coupon') {
            throw new NotACouponPromotion(Rows::string($row, 'code'), Rows::string($row, 'activator'));
        }
    }

    private function lock(CouponId $id): object
    {
        $row = $this->db()->table($this->tables->table('coupons'))
            ->where('id', $id->value)
            ->lock('for update')
            ->first();

        return $row ?? throw new CouponNotFound($id->value);
    }

    private function lockCodes(): void
    {
        $this->db()->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'commerz.coupon_codes:'.$this->tables->table('coupons'),
        ]);
    }

    /**
     * @param  list<object>  $rows  rows of the coupons table
     * @return list<CouponView> in the order of the rows
     */
    private function views(array $rows): array
    {
        $consumed = $this->consumed(array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows));

        return array_map(static fn (object $row): CouponView => new CouponView(
            new CouponId(Rows::string($row, 'id')),
            new PromotionId(Rows::string($row, 'promotion_id')),
            Rows::nullableString($row, 'batch_id') === null ? null : new CouponBatchId(Rows::string($row, 'batch_id')),
            Rows::string($row, 'code'),
            CouponStatus::from(Rows::string($row, 'status')),
            Rows::nullableInt($row, 'usage_limit'),
            $consumed[Rows::string($row, 'id')] ?? 0,
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        ), $rows);
    }

    /**
     * Orders that hold each coupon: its usages whose redemption is `consumed`, from the log.
     *
     * @param  list<string>  $ids
     * @return array<string, int> by coupon id
     */
    private function consumed(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $counts = [];

        $rows = $this->db()->table($this->tables->table('promotion_usages').' as u')
            ->join($this->tables->table('promotion_redemptions').' as r', 'r.id', '=', 'u.redemption_id')
            ->whereIn('u.coupon_id', $ids)
            ->where('r.state', 'consumed')
            ->groupBy('u.coupon_id')
            ->selectRaw('u.coupon_id, count(*) as total')
            ->get();

        foreach ($rows as $row) {
            $counts[Rows::string($row, 'coupon_id')] = Rows::int($row, 'total');
        }

        return $counts;
    }

    /** @param  array<array-key, mixed>  $stored */
    private static function string(array $stored, string $key): string
    {
        return is_string($stored[$key] ?? null) ? $stored[$key] : throw new UnexpectedValueException("Stored batch has no '{$key}'.");
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

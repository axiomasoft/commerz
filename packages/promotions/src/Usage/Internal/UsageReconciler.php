<?php

declare(strict_types=1);

namespace Commerz\Promotions\Usage\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Money\Currency;
use Commerz\Promotions\Common\Internal\Rows;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Internal\RuleCodec;
use Commerz\Promotions\Rules\Internal\StoredRule;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use RuntimeException;

/**
 * Read-only check of PRM-01's bookkeeping: every kept counter equals the usage log of the orders that still hold the
 * promotion (redemption `consumed`), a counter without a limit is zero, and every rule and activator of a promotion that
 * can still apply loads. All reads share one `repeatable read` read-only snapshot, so traffic cannot fake a discrepancy.
 */
#[Internal]
final readonly class UsageReconciler
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private RuleCodec $codec,
        private Contributions $contributions,
    ) {}

    /** @return list<Discrepancy> */
    public function check(): array
    {
        $db = $this->db();
        $nested = $db->transactionLevel() > 0;
        $db->beginTransaction();

        try {
            if (! $nested) {
                $db->statement('set transaction isolation level repeatable read read only');
            }

            return [...$this->promotions(), ...$this->coupons(), ...$this->customers(), ...$this->definitions()];
        } finally {
            $db->rollBack();
        }
    }

    /** @return list<Discrepancy> */
    private function promotions(): array
    {
        $rows = $this->db()->select(
            'select p.code, p.used, p.spent_minor,'
            .' case when p.usage_limit is null then 0 else coalesce(c.used, 0) end as expected_used,'
            .' case when p.budget_minor is null then 0 else coalesce(c.spent, 0) end as expected_spent'
            ." from {$this->table('promotions')} p left join ("
            .'select u.promotion_id, count(*)::bigint as used, sum(u.discount_minor)::bigint as spent'
            ." from {$this->consumed()} group by u.promotion_id"
            .') c on c.promotion_id = p.id order by p.code',
        );

        $found = [];

        foreach ($this->objects($rows) as $row) {
            $ref = 'promotion:'.Rows::string($row, 'code');

            if (Rows::int($row, 'used') !== Rows::int($row, 'expected_used')) {
                $found[] = new Discrepancy(Discrepancy::PROMOTION_USED, $ref, (string) Rows::int($row, 'expected_used'), (string) Rows::int($row, 'used'));
            }

            if (Rows::int($row, 'spent_minor') !== Rows::int($row, 'expected_spent')) {
                $found[] = new Discrepancy(Discrepancy::PROMOTION_SPENT, $ref, (string) Rows::int($row, 'expected_spent'), (string) Rows::int($row, 'spent_minor'));
            }
        }

        return $found;
    }

    /** @return list<Discrepancy> */
    private function coupons(): array
    {
        $rows = $this->db()->select(
            'select k.code, k.used, case when k.usage_limit is null then 0 else coalesce(c.used, 0) end as expected'
            ." from {$this->table('coupons')} k left join ("
            ."select u.coupon_id, count(*)::bigint as used from {$this->consumed()} and u.coupon_id is not null group by u.coupon_id"
            .') c on c.coupon_id = k.id'
            .' where k.used <> case when k.usage_limit is null then 0 else coalesce(c.used, 0) end order by k.code',
        );

        return array_map(static fn (object $row): Discrepancy => new Discrepancy(
            Discrepancy::COUPON_USED,
            'coupon:'.Rows::string($row, 'code'),
            (string) Rows::int($row, 'expected'),
            (string) Rows::int($row, 'used'),
        ), $this->objects($rows));
    }

    /**
     * Rows of customers against their consumed orders; a promotion without a per-customer limit keeps none above 0.
     *
     * @return list<Discrepancy>
     */
    private function customers(): array
    {
        $rows = $this->db()->select(
            'with expected as ('
            .'select u.promotion_id, r.customer_ref, count(*)::bigint as used'
            ." from {$this->consumed()} and r.customer_ref is not null group by u.promotion_id, r.customer_ref"
            .'), compared as ('
            .'select coalesce(a.promotion_id, e.promotion_id) as promotion_id, coalesce(a.customer_ref, e.customer_ref) as customer_ref,'
            .' coalesce(a.used, 0) as actual, coalesce(e.used, 0) as logged'
            ." from {$this->table('promotion_customer_usages')} a full join expected e"
            .' on e.promotion_id = a.promotion_id and e.customer_ref = a.customer_ref'
            .')'
            .' select p.code, x.customer_ref, x.actual,'
            .' case when p.per_customer_limit is null then 0 else x.logged end as expected'
            ." from compared x join {$this->table('promotions')} p on p.id = x.promotion_id"
            .' where x.actual <> case when p.per_customer_limit is null then 0 else x.logged end'
            .' order by p.code, x.customer_ref',
        );

        return array_map(static fn (object $row): Discrepancy => new Discrepancy(
            Discrepancy::CUSTOMER_USED,
            'promotion:'.Rows::string($row, 'code').'/'.Rows::string($row, 'customer_ref'),
            (string) Rows::int($row, 'expected'),
            (string) Rows::int($row, 'actual'),
        ), $this->objects($rows));
    }

    /**
     * Activators and rules no installed plugin can run: such a promotion is skipped by every quote until fixed.
     *
     * @return list<Discrepancy>
     */
    private function definitions(): array
    {
        $db = $this->db();
        $promotions = $db->table($this->tables->table('promotions'))
            ->where('status', '<>', PromotionStatus::Archived->value)
            ->orderBy('code')
            ->get(['id', 'code', 'activator', 'stage', 'currency'])
            ->all();

        $codes = [];
        $scopes = [];
        $found = [];

        foreach ($promotions as $promotion) {
            $code = Rows::string($promotion, 'code');
            $codes[Rows::string($promotion, 'id')] = $code;
            $currency = Rows::nullableString($promotion, 'currency');
            $scopes[Rows::string($promotion, 'id')] = new RuleScope(
                PromotionStage::from(Rows::string($promotion, 'stage')),
                $currency === null ? null : Currency::of($currency),
            );
            $activator = Rows::string($promotion, 'activator');

            if (! $this->contributions->has(PromotionActivator::KIND, $activator)) {
                $found[] = new Discrepancy(Discrepancy::UNSUPPORTED_ACTIVATOR, "promotion:{$code}", 'supported', "no activator contributes '{$activator}'");
            }
        }

        if ($codes === []) {
            return $found;
        }

        $rules = $db->table($this->tables->table('promotion_rules'))
            ->whereIn('promotion_id', array_keys($codes))
            ->orderBy('promotion_id')
            ->orderBy('role')
            ->orderBy('position')
            ->get(['promotion_id', 'role', 'position', 'rule_key', 'config_version', 'config'])
            ->all();

        $unsupported = [];

        foreach ($rules as $row) {
            $role = Rows::string($row, 'role') === RuleCodec::EFFECT ? RuleCodec::EFFECT : RuleCodec::CONDITION;

            try {
                $loaded = $this->codec->decode(new StoredRule(Rows::string($row, 'rule_key'), Rows::int($row, 'config_version'), RuleParams::fromInput(Rows::json($row, 'config'))), $role, $scopes[Rows::string($row, 'promotion_id')]);
                $reason = $loaded->reason;
            } catch (InvalidRuleConfig|RuntimeException $e) {
                $reason = "stored parameters cannot be read: {$e->getMessage()}";
            }

            if ($reason !== null) {
                $code = $codes[Rows::string($row, 'promotion_id')];
                $unsupported[$code.'/'.$role.'.'.Rows::int($row, 'position')] = new Discrepancy(
                    Discrepancy::UNSUPPORTED_RULE,
                    "promotion:{$code}/{$role}.".Rows::int($row, 'position'),
                    'supported',
                    $reason,
                );
            }
        }

        ksort($unsupported, SORT_STRING);

        return [...$found, ...array_values($unsupported)];
    }

    /** `promotion_usages u` joined with the redemptions still `consumed`, open for more `and …` conditions. */
    private function consumed(): string
    {
        return "{$this->table('promotion_usages')} u join {$this->table('promotion_redemptions')} r"
            ." on r.id = u.redemption_id where r.state = 'consumed'";
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<object>
     */
    private function objects(array $rows): array
    {
        return array_values(array_filter($rows, is_object(...)));
    }

    private function table(string $name): string
    {
        $table = $this->tables->table($name);

        return $this->db()->table($table)->getGrammar()->wrapTable($table);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

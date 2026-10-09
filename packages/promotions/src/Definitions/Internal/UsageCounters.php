<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Promotions\Common\Internal\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * Brings the counters of a promotion back in line with its usage log after a limit or the budget changed. A counter is
 * kept only while its limit is set, so a limit set later starts from what orders have already used. The caller holds
 * the promotion row `for update`: that waits for every order being placed with the promotion (they hold `for key
 * share`), so the log read here is complete and none can slip in before the new limit applies.
 */
final readonly class UsageCounters
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
    ) {}

    public function recompute(string $promotionId): void
    {
        $db = $this->db();
        $promotions = $this->tables->table('promotions');
        $promotion = $db->table($promotions)->where('id', $promotionId)->first(['usage_limit', 'budget_minor', 'per_customer_limit'])
            ?? throw new RuntimeException("Promotion '{$promotionId}' is missing.");

        $used = Rows::nullableInt($promotion, 'usage_limit') === null ? 0 : $this->consumed($db, $promotionId)->count();
        $spent = Rows::nullableInt($promotion, 'budget_minor') === null ? 0 : $this->discounted($db, $promotionId);

        $db->table($promotions)->where('id', $promotionId)->update(['used' => $used, 'spent_minor' => $spent]);

        $customers = $this->tables->table('promotion_customer_usages');
        $db->table($customers)->where('promotion_id', $promotionId)->delete();

        if (Rows::nullableInt($promotion, 'per_customer_limit') !== null) {
            $db->table($customers)->insertUsing(
                ['promotion_id', 'customer_ref', 'used', 'updated_at'],
                $this->consumed($db, $promotionId)
                    ->whereNotNull('r.customer_ref')
                    ->groupBy('u.promotion_id', 'r.customer_ref')
                    ->selectRaw('u.promotion_id, r.customer_ref, count(*), now()'),
            );
        }
    }

    private function discounted(ConnectionInterface $db, string $promotionId): int
    {
        $row = $this->consumed($db, $promotionId)->selectRaw('coalesce(sum(u.discount_minor), 0)::bigint as total')->first();

        return $row === null ? 0 : Rows::int($row, 'total');
    }

    /** The usages of orders that still hold the promotion: their redemption is `consumed`, not `restored`. */
    private function consumed(ConnectionInterface $db, string $promotionId): Builder
    {
        return $db->table($this->tables->table('promotion_usages').' as u')
            ->join($this->tables->table('promotion_redemptions').' as r', 'r.id', '=', 'u.redemption_id')
            ->where('u.promotion_id', $promotionId)
            ->where('r.state', 'consumed');
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

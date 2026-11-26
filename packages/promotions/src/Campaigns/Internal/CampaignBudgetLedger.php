<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Money\Currency;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Campaigns\Exceptions\CampaignArchived;
use Commerz\Promotions\Campaigns\Exceptions\CampaignBudgetExceeded;
use Commerz\Promotions\Campaigns\Exceptions\CampaignNotFound;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Atomic campaign budget & usage enforcement (INV-PRM-001).
 *
 * Protocol: locks campaign rows in `id ASC` order with `FOR KEY SHARE` (blocking concurrent `FOR UPDATE` definition
 * changes without serializing unlimited campaigns), validates status and currency, and enforces `spend_cap_minor` and
 * `usage_cap` in one conditional `UPDATE` (`FOR NO KEY UPDATE` implicitly) so concurrent checkouts can never breach
 * `spend_cap_minor` or `usage_cap`.
 */
#[Internal]
final readonly class CampaignBudgetLedger
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Clock $clock,
    ) {}

    /**
     * Atomically consumes spend/usage across one or more campaigns in `id ASC` order.
     *
     * @param  array<string, array{discountMinor: int, uses: int}>  $demands  keyed by campaign UUID
     * @return array<string, object> campaign rows by id
     *
     * @throws CampaignNotFound|CampaignArchived|CampaignBudgetExceeded|InvalidCommand
     */
    public function consumeMany(array $demands, Currency $currency): array
    {
        if ($demands === []) {
            return [];
        }

        ksort($demands, SORT_STRING);
        $db = $this->db();
        $table = $this->tables->table('campaigns');

        $query = $db->table($table)
            ->whereIn('id', array_keys($demands))
            ->orderBy('id');
        $this->lock($query, 'for key share');

        $rows = [];

        foreach ($query->get(['id', 'code', 'status', 'currency', 'spend_cap_minor', 'usage_cap', 'spent_minor', 'used'])->all() as $row) {
            $rows[Rows::string($row, 'id')] = $row;
        }

        foreach ($demands as $campaignId => $_demand) {
            $row = $rows[$campaignId] ?? throw new CampaignNotFound($campaignId);
            $status = CampaignStatus::from(Rows::string($row, 'status'));

            if ($status === CampaignStatus::Archived) {
                throw new CampaignArchived(Rows::string($row, 'code'));
            }

            if ($status !== CampaignStatus::Active) {
                throw new CampaignBudgetExceeded($campaignId, 'campaign_not_active');
            }

            $own = Rows::nullableString($row, 'currency');

            if ($own !== null && $own !== $currency->code) {
                throw new InvalidCommand('campaign', "campaign '{$campaignId}' is in {$own}, the discounts in {$currency->code}", 'promotions.currency_mismatch');
            }
        }

        $now = PgTimestamp::now($this->clock);

        foreach ($demands as $campaignId => $demand) {
            $row = $rows[$campaignId];

            if (Rows::nullableInt($row, 'spend_cap_minor') === null && Rows::nullableInt($row, 'usage_cap') === null) {
                continue;
            }

            $discount = $demand['discountMinor'];
            $uses = $demand['uses'];
            $updated = $db->update(
                "update {$this->wrap($table)}"
                .' set used = used + case when usage_cap is null then 0 else ? end,'
                .' spent_minor = spent_minor + case when spend_cap_minor is null then 0 else ? end,'
                .' updated_at = ?'
                .' where id = ?'
                .' and (usage_cap is null or ? <= usage_cap - used)'
                .' and (spend_cap_minor is null or ? <= spend_cap_minor - spent_minor)',
                [$uses, $discount, $now, $campaignId, $uses, $discount],
            );

            if ($updated === 0) {
                $current = $db->table($table)->where('id', $campaignId)->first(['used', 'usage_cap'])
                    ?? throw new RuntimeException("Campaign '{$campaignId}' is missing under its lock.");
                $usageCap = Rows::nullableInt($current, 'usage_cap');

                throw new CampaignBudgetExceeded(
                    $campaignId,
                    $usageCap !== null && $uses > $usageCap - Rows::int($current, 'used')
                        ? CampaignBudgetExceeded::USAGE_CAP
                        : CampaignBudgetExceeded::SPEND_CAP,
                );
            }
        }

        return $rows;
    }

    /**
     * Restores spend/usage across one or more campaigns in `id ASC` order, clamping counters at zero.
     *
     * @param  array<string, array{discountMinor: int, uses: int}>  $restorations  keyed by campaign UUID
     */
    public function restoreMany(array $restorations): void
    {
        if ($restorations === []) {
            return;
        }

        ksort($restorations, SORT_STRING);
        $db = $this->db();
        $table = $this->tables->table('campaigns');

        $query = $db->table($table)
            ->whereIn('id', array_keys($restorations))
            ->orderBy('id');
        $this->lock($query, 'for key share');

        $rows = $query->get(['id', 'spend_cap_minor', 'usage_cap'])->all();
        $now = PgTimestamp::now($this->clock);

        foreach ($rows as $row) {
            if (Rows::nullableInt($row, 'spend_cap_minor') === null && Rows::nullableInt($row, 'usage_cap') === null) {
                continue;
            }

            $id = Rows::string($row, 'id');
            $restore = $restorations[$id];

            $db->update(
                "update {$this->wrap($table)}"
                .' set used = case when usage_cap is null then used when used > ? then used - ? else 0 end,'
                .' spent_minor = case when spend_cap_minor is null then spent_minor when spent_minor > ? then spent_minor - ? else 0 end,'
                .' updated_at = ?'
                .' where id = ?',
                [$restore['uses'], $restore['uses'], $restore['discountMinor'], $restore['discountMinor'], $now, $id],
            );
        }
    }

    /**
     * Recomputes `used` and `spent_minor` from consumed `promotion_usages` while caller holds `FOR UPDATE`.
     */
    public function recompute(string $campaignId): void
    {
        $db = $this->db();
        $campaigns = $this->tables->table('campaigns');
        $campaign = $db->table($campaigns)->where('id', $campaignId)->first(['spend_cap_minor', 'usage_cap'])
            ?? throw new RuntimeException("Campaign '{$campaignId}' is missing.");

        $consumed = $db->table($this->tables->table('promotion_usages').' as u')
            ->join($this->tables->table('promotion_redemptions').' as r', 'r.id', '=', 'u.redemption_id')
            ->where('u.campaign_id', $campaignId)
            ->where('r.state', 'consumed');

        $used = Rows::nullableInt($campaign, 'usage_cap') === null ? 0 : (clone $consumed)->count();
        $spent = 0;

        if (Rows::nullableInt($campaign, 'spend_cap_minor') !== null) {
            $sumRow = (clone $consumed)->selectRaw('coalesce(sum(u.discount_minor), 0) as total')->first();
            $spent = $sumRow === null ? 0 : Rows::int($sumRow, 'total');
        }

        $db->table($campaigns)->where('id', $campaignId)->update([
            'used' => $used,
            'spent_minor' => $spent,
            'updated_at' => PgTimestamp::now($this->clock),
        ]);
    }

    public static function budgetFromRow(object $row): CampaignBudget
    {
        $currency = Rows::nullableString($row, 'currency');

        return new CampaignBudget(
            spendCapMinor: Rows::nullableInt($row, 'spend_cap_minor'),
            spentMinor: Rows::int($row, 'spent_minor'),
            usageCap: Rows::nullableInt($row, 'usage_cap'),
            used: Rows::int($row, 'used'),
            currency: $currency === null ? null : Currency::of($currency),
        );
    }

    private function lock(Builder $query, string $mode): void
    {
        $db = $this->db();

        if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
            $query->lock($mode);
        }
    }

    private function wrap(string $table): string
    {
        return $this->db()->table($table)->getGrammar()->wrapTable($table);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

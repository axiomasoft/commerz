<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Common\Internal\Rows;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\PromotionUsageSummary;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Definitions\Data\UsageLimits;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Internal\RuleCodec;
use Commerz\Promotions\Rules\Internal\StoredRule;
use Commerz\Schema\TableNames;
use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/** Reads promotion rows with their channels, rules and usage into views; a page of views takes four queries. */
final readonly class PromotionRows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
    ) {}

    /**
     * @param  list<object>  $rows  rows of the promotions table
     * @return list<PromotionView> in the order of the rows
     */
    public function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $channels = $this->channelsOf($ids);
        $rules = $this->rulesOf($ids);
        $usage = $this->usageOf($ids);

        return array_map(
            fn (object $row): PromotionView => $this->view($row, $channels, $rules, $usage),
            $rows,
        );
    }

    /**
     * The rules of one role as the database keeps them, in order.
     *
     * @param  'condition'|'effect'  $role
     * @return list<StoredRule>
     */
    public function storedRules(string $promotionId, string $role): array
    {
        return array_values(array_map(
            static fn (object $row): StoredRule => new StoredRule(
                Rows::string($row, 'rule_key'),
                Rows::int($row, 'config_version'),
                RuleParams::fromInput(Rows::json($row, 'config')),
            ),
            $this->db()->table($this->tables->table('promotion_rules'))
                ->where('promotion_id', $promotionId)
                ->where('role', $role)
                ->orderBy('position')
                ->get(['rule_key', 'config_version', 'config'])
                ->all(),
        ));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<ChannelId>> by promotion id, sorted by channel id
     */
    private function channelsOf(array $ids): array
    {
        $rows = $this->db()->table($this->tables->table('promotion_channels'))
            ->whereIn('promotion_id', $ids)
            ->orderBy('channel_id')
            ->get(['promotion_id', 'channel_id'])
            ->all();

        $byPromotion = [];

        foreach ($rows as $row) {
            $byPromotion[Rows::string($row, 'promotion_id')][] = new ChannelId(Rows::string($row, 'channel_id'));
        }

        return $byPromotion;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, list<RuleSpec>>> by promotion id and role
     */
    private function rulesOf(array $ids): array
    {
        $rows = $this->db()->table($this->tables->table('promotion_rules'))
            ->whereIn('promotion_id', $ids)
            ->orderBy('promotion_id')
            ->orderBy('role')
            ->orderBy('position')
            ->get(['promotion_id', 'role', 'rule_key', 'config'])
            ->all();

        $byPromotion = [];

        foreach ($rows as $row) {
            $byPromotion[Rows::string($row, 'promotion_id')][Rows::string($row, 'role')][] = new RuleSpec(
                Rows::string($row, 'rule_key'),
                RuleParams::fromInput(Rows::json($row, 'config'))->toArray(),
            );
        }

        return $byPromotion;
    }

    /**
     * Orders and discounts by promotion, counted from the usage log of orders not restored, not from the counters.
     *
     * @param  list<string>  $ids
     * @return array<string, PromotionUsageSummary>
     */
    private function usageOf(array $ids): array
    {
        $rows = $this->db()->table($this->tables->table('promotion_usages').' as u')
            ->join($this->tables->table('promotion_redemptions').' as r', 'r.id', '=', 'u.redemption_id')
            ->whereIn('u.promotion_id', $ids)
            ->where('r.state', 'consumed')
            ->groupBy('u.promotion_id', 'u.currency')
            ->orderBy('u.currency')
            ->selectRaw('u.promotion_id, u.currency, count(*) as orders, sum(u.discount_minor)::bigint as discount_minor')
            ->get()
            ->all();

        $orders = [];
        $discounts = [];

        foreach ($rows as $row) {
            $id = Rows::string($row, 'promotion_id');
            $orders[$id] = ($orders[$id] ?? 0) + Rows::int($row, 'orders');
            $discounts[$id][] = Money::ofMinor(Rows::int($row, 'discount_minor'), Currency::of(Rows::string($row, 'currency')));
        }

        $summaries = [];

        foreach ($orders as $id => $count) {
            $summaries[$id] = new PromotionUsageSummary($count, $discounts[$id]);
        }

        return $summaries;
    }

    /**
     * @param  array<string, list<ChannelId>>  $channels
     * @param  array<string, array<string, list<RuleSpec>>>  $rules
     * @param  array<string, PromotionUsageSummary>  $usage
     */
    private function view(object $row, array $channels, array $rules, array $usage): PromotionView
    {
        $id = Rows::string($row, 'id');
        $currency = Rows::nullableString($row, 'currency');
        $budget = Rows::nullableInt($row, 'budget_minor');

        return new PromotionView(
            new PromotionId($id),
            Rows::string($row, 'code'),
            Rows::string($row, 'name'),
            PromotionStage::from(Rows::string($row, 'stage')),
            Rows::string($row, 'activator'),
            Stacking::from(Rows::string($row, 'stacking')),
            Rows::bool($row, 'stop_further'),
            Rows::int($row, 'priority'),
            $currency === null ? null : Currency::of($currency),
            PromotionStatus::from(Rows::string($row, 'status')),
            self::date($row, 'starts_at'),
            self::date($row, 'ends_at'),
            $channels[$id] ?? [],
            $rules[$id][RuleCodec::CONDITION] ?? [],
            $rules[$id][RuleCodec::EFFECT] ?? [],
            new UsageLimits(Rows::nullableInt($row, 'usage_limit'), Rows::nullableInt($row, 'per_customer_limit')),
            $budget === null || $currency === null ? null : Money::ofMinor($budget, Currency::of($currency)),
            Rows::int($row, 'revision'),
            $usage[$id] ?? new PromotionUsageSummary(0, []),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    private static function date(object $row, string $column): ?DateTimeImmutable
    {
        $value = Rows::nullableString($row, $column);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

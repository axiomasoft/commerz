<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Promotions\Campaigns\Data\CampaignId;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Campaigns\Exceptions\CampaignArchived;
use Commerz\Promotions\Campaigns\Exceptions\CampaignNotFound;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Definitions\Contracts\PromotionDefinitions;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\AssignPromotionChannels;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\ExplainPromotions;
use Commerz\Promotions\Definitions\Data\ListPromotions;
use Commerz\Promotions\Definitions\Data\PausePromotion;
use Commerz\Promotions\Definitions\Data\PromotionExplanation;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Definitions\Data\ResumePromotion;
use Commerz\Promotions\Definitions\Data\UnassignPromotionChannels;
use Commerz\Promotions\Definitions\Data\UpdatePromotion;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Definitions\Events\PromotionChanged;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Commerz\Promotions\Evaluation\Internal\PromotionExplainer;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Data\RuleSpec;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Internal\RuleCodec;
use Commerz\Promotions\Rules\Internal\StoredRule;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Data\ChannelId;
use Commerz\Time\PgTimestamp;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

/**
 * Lock order: the promotion row `for update` first, in every change (D27: it waits for the orders being placed with the
 * promotion, which hold `for key share`, and keeps new ones out until the counters are recomputed), then its channel,
 * rule and counter rows. Nothing here takes a coupon or a customer row.
 */
#[Internal]
final readonly class DatabasePromotionDefinitions implements PromotionDefinitions
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private Channels $channels,
        private Contributions $contributions,
        private RuleCodec $codec,
        private PromotionRows $rows,
        private UsageCounters $counters,
        private PromotionExplainer $explainer,
    ) {}

    public function create(CreatePromotion $command): PromotionView
    {
        return $this->transactions->run(function () use ($command): PromotionView {
            $currency = self::currencyOf($command);

            if (! $this->contributions->has(PromotionActivator::KIND, $command->activator)) {
                throw new InvalidCommand('activator', "no activator contributes '{$command->activator}'", 'promotions.unknown_activator');
            }

            $scope = new RuleScope($command->stage, $currency);
            $conditions = $this->codec->encode($command->conditions, RuleCodec::CONDITION, $scope);
            $effects = $this->codec->encode($command->effects, RuleCodec::EFFECT, $scope);
            $channels = self::unique($command->channels);

            foreach ($channels as $channel) {
                $this->channels->assertActive($channel, $currency);
            }

            if ($command->campaignId instanceof CampaignId) {
                $this->assertCampaignUsable($command->campaignId, $currency);
            }

            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('promotions'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'stage' => $command->stage->value,
                    'activator' => $command->activator,
                    'stacking' => $command->stacking->value,
                    'stop_further' => $command->stopFurther,
                    'priority' => $command->priority,
                    'budget_minor' => $command->budget?->amountMinor,
                    'currency' => $currency?->code,
                    'status' => $command->status->value,
                    'starts_at' => self::timestamp($command->startsAt),
                    'ends_at' => self::timestamp($command->endsAt),
                    'usage_limit' => $command->limits->total,
                    'per_customer_limit' => $command->limits->perCustomer,
                    'used' => 0,
                    'spent_minor' => 0,
                    'revision' => 1,
                    'campaign_id' => $command->campaignId?->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Promotion', $command->code);
            }

            $this->insertChannels($id, $channels);
            $this->insertRules($id, RuleCodec::CONDITION, $conditions);
            $this->insertRules($id, RuleCodec::EFFECT, $effects);
            $this->events->dispatch(new PromotionChanged(new PromotionId($id)));

            return $this->find(new PromotionId($id));
        });
    }

    public function update(UpdatePromotion $command): PromotionView
    {
        return $this->transactions->run(function () use ($command): PromotionView {
            $id = $command->promotion->value;
            $row = $this->lockActive($command->promotion);
            $currencyCode = Rows::nullableString($row, 'currency');
            $currency = $currencyCode === null ? null : Currency::of($currencyCode);
            $scope = new RuleScope(PromotionStage::from(Rows::string($row, 'stage')), $currency);

            $changes = [];
            $definition = false; // rules, limits, budget or window changed: a new revision
            $counters = false; // limits or budget changed: the counters are recomputed

            if ($command->name !== null && $command->name !== Rows::string($row, 'name')) {
                $changes['name'] = $command->name;
            }

            if ($command->priority !== null && $command->priority !== Rows::int($row, 'priority')) {
                $changes['priority'] = $command->priority;
            }

            if ($command->stacking instanceof Stacking && $command->stacking->value !== Rows::string($row, 'stacking')) {
                $changes['stacking'] = $command->stacking->value;
            }

            if ($command->stopFurther !== null && $command->stopFurther !== Rows::bool($row, 'stop_further')) {
                $changes['stop_further'] = $command->stopFurther;
            }

            if ($command->window !== null
                && (! self::sameInstant($command->window->startsAt, self::date($row, 'starts_at')) || ! self::sameInstant($command->window->endsAt, self::date($row, 'ends_at')))) {
                $changes['starts_at'] = self::timestamp($command->window->startsAt);
                $changes['ends_at'] = self::timestamp($command->window->endsAt);
                $definition = true;
            }

            if ($command->limits !== null
                && ($command->limits->total !== Rows::nullableInt($row, 'usage_limit') || $command->limits->perCustomer !== Rows::nullableInt($row, 'per_customer_limit'))) {
                $changes['usage_limit'] = $command->limits->total;
                $changes['per_customer_limit'] = $command->limits->perCustomer;
                $definition = $counters = true;
            }

            $budget = Rows::nullableInt($row, 'budget_minor');

            if ($command->clearBudget && $budget !== null) {
                $changes['budget_minor'] = null;
                $definition = $counters = true;
            }

            if ($command->budget instanceof Money) {
                if (! $currency instanceof Currency || ! $command->budget->currency->equals($currency)) {
                    throw new InvalidCommand('budget', $currency instanceof Currency ? "must be in {$currency->code}" : 'a promotion without a currency has no budget', 'promotions.currency_mismatch');
                }

                if ($command->budget->amountMinor !== $budget) {
                    $changes['budget_minor'] = $command->budget->amountMinor;
                    $definition = $counters = true;
                }
            }

            $currentCampaign = property_exists($row, 'campaign_id') ? Rows::nullableString($row, 'campaign_id') : null;

            if ($command->clearCampaign && $currentCampaign !== null) {
                $changes['campaign_id'] = null;
                $definition = true;
            }

            if ($command->campaignId instanceof CampaignId && $command->campaignId->value !== $currentCampaign) {
                $this->assertCampaignUsable($command->campaignId, $currency);
                $changes['campaign_id'] = $command->campaignId->value;
                $definition = true;
            }

            $replaced = [];

            foreach ([[RuleCodec::CONDITION, $command->conditions], [RuleCodec::EFFECT, $command->effects]] as [$role, $specs]) {
                if ($specs === null) {
                    continue;
                }

                self::assertCurrency($specs, $role.'s', $currency);
                $encoded = $this->codec->encode($specs, $role, $scope);

                if (! self::sameRules($encoded, $this->rows->storedRules($id, $role))) {
                    $replaced[] = [$role, $encoded];
                    $definition = true;
                }
            }

            if ($changes !== [] || $replaced !== []) {
                $db = $this->db();
                $changes['updated_at'] = PgTimestamp::now();

                if ($definition) {
                    $changes['revision'] = $db->raw('revision + 1');
                }

                $db->table($this->tables->table('promotions'))->where('id', $id)->update($changes);

                foreach ($replaced as [$role, $rules]) {
                    $db->table($this->tables->table('promotion_rules'))->where('promotion_id', $id)->where('role', $role)->delete();
                    $this->insertRules($id, $role, $rules);
                }

                if ($counters) {
                    $this->counters->recompute($id);
                }

                $this->events->dispatch(new PromotionChanged($command->promotion));
            }

            return $this->find($command->promotion);
        });
    }

    public function pause(PausePromotion $command): PromotionView
    {
        return $this->transition($command->promotion, PromotionStatus::Paused);
    }

    public function resume(ResumePromotion $command): PromotionView
    {
        return $this->transition($command->promotion, PromotionStatus::Active);
    }

    public function archive(ArchivePromotion $command): PromotionView
    {
        return $this->transition($command->promotion, PromotionStatus::Archived);
    }

    public function assignChannels(AssignPromotionChannels $command): PromotionView
    {
        return $this->transactions->run(function () use ($command): PromotionView {
            $row = $this->lockActive($command->promotion);
            $currency = Rows::nullableString($row, 'currency');
            $currency = $currency === null ? null : Currency::of($currency);
            $channels = self::unique($command->channels);

            foreach ($channels as $channel) {
                $this->channels->assertActive($channel, $currency);
            }

            if ($this->insertChannels($command->promotion->value, $channels) > 0) {
                $this->touch($command->promotion);
                $this->events->dispatch(new PromotionChanged($command->promotion));
            }

            return $this->find($command->promotion);
        });
    }

    public function unassignChannels(UnassignPromotionChannels $command): PromotionView
    {
        return $this->transactions->run(function () use ($command): PromotionView {
            $this->lockActive($command->promotion);
            $channels = $this->db()->table($this->tables->table('promotion_channels'))->where('promotion_id', $command->promotion->value);

            $removed = (clone $channels)
                ->whereIn('channel_id', array_map(static fn (ChannelId $channel): string => $channel->value, $command->channels))
                ->delete();

            if ($channels->doesntExist()) {
                throw new InvalidCommand('channels', 'a promotion keeps at least one channel', 'promotions.no_channels');
            }

            if ($removed > 0) {
                $this->touch($command->promotion);
                $this->events->dispatch(new PromotionChanged($command->promotion));
            }

            return $this->find($command->promotion);
        });
    }

    public function find(PromotionId $id): PromotionView
    {
        $row = $this->db()->table($this->tables->table('promotions'))->where('id', $id->value)->first();

        return $this->rows->views([$row ?? throw new PromotionNotFound($id->value)])[0];
    }

    public function findByCode(string $code): PromotionView
    {
        $row = $this->db()->table($this->tables->table('promotions'))->where('code', $code)->first();

        return $this->rows->views([$row ?? throw new PromotionNotFound($code)])[0];
    }

    public function list(ListPromotions $query): Page
    {
        $builder = $this->db()->table($this->tables->table('promotions'))->orderBy('code')->limit($query->first + 1);

        if ($query->status instanceof PromotionStatus) {
            $builder->where('status', $query->status->value);
        }

        if ($query->stage instanceof PromotionStage) {
            $builder->where('stage', $query->stage->value);
        }

        if ($query->channel instanceof ChannelId) {
            $builder->whereIn('id', $this->db()->table($this->tables->table('promotion_channels'))
                ->select('promotion_id')
                ->where('channel_id', $query->channel->value));
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = $this->rows->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    public function explain(ExplainPromotions $query): PromotionExplanation
    {
        return $this->explainer->explain($query);
    }

    /** Moves to `$target` from the other live state; already there, or archived and archiving, is no change. */
    private function transition(PromotionId $id, PromotionStatus $target): PromotionView
    {
        return $this->transactions->run(function () use ($id, $target): PromotionView {
            $row = $this->lock($id);
            $current = PromotionStatus::from(Rows::string($row, 'status'));

            if ($current === $target) {
                return $this->find($id);
            }

            if ($current === PromotionStatus::Archived) {
                throw new PromotionArchived(Rows::string($row, 'code'));
            }

            $this->touch($id, ['status' => $target->value]);
            $this->events->dispatch(new PromotionChanged($id));

            return $this->find($id);
        });
    }

    /**
     * The currency of a new promotion: its own, else the one of the budget and of the amounts in its rules.
     *
     * @throws InvalidCommand promotions.currency_mismatch when two of them differ
     */
    private static function currencyOf(CreatePromotion $command): ?Currency
    {
        $found = array_merge(
            $command->currency instanceof Currency ? [$command->currency->code] : [],
            $command->budget instanceof Money ? [$command->budget->currency->code] : [],
            self::currenciesOf($command->conditions, 'conditions'),
            self::currenciesOf($command->effects, 'effects'),
        );
        $found = array_values(array_unique($found));
        sort($found);

        if (count($found) > 1) {
            throw new InvalidCommand('currency', 'the currency, the budget and the amounts in the rules must agree, got '.implode(', ', $found), 'promotions.currency_mismatch');
        }

        return $found === [] ? null : Currency::of($found[0]);
    }

    /**
     * @param  list<RuleSpec>  $specs
     * @return list<string> currency codes of the amounts in the rules
     */
    private static function currenciesOf(array $specs, string $name): array
    {
        $found = [];

        foreach ($specs as $index => $spec) {
            try {
                $found = array_merge($found, RuleParams::fromInput($spec->params)->currencies());
            } catch (InvalidRuleConfig $e) {
                throw new InvalidCommand("{$name}.{$index}.params.{$e->field}", $e->reason, 'promotions.invalid_rule_config');
            }
        }

        return $found;
    }

    /**
     * @param  list<RuleSpec>  $specs
     *
     * @throws InvalidCommand promotions.currency_mismatch when an amount is not in the currency of the promotion
     */
    private static function assertCurrency(array $specs, string $name, ?Currency $currency): void
    {
        $found = array_values(array_unique(self::currenciesOf($specs, $name)));

        if ($found !== [] && (! $currency instanceof Currency || $found !== [$currency->code])) {
            throw new InvalidCommand($name, $currency instanceof Currency ? "amounts must be in {$currency->code}" : 'a promotion without a currency has no amounts', 'promotions.currency_mismatch');
        }
    }

    /**
     * @param  list<StoredRule>  $new
     * @param  list<StoredRule>  $stored
     */
    private static function sameRules(array $new, array $stored): bool
    {
        if (count($new) !== count($stored)) {
            return false;
        }

        // jsonb does not keep the order of keys; the parameters are canonical again once read through RuleParams.
        return array_all($new, static fn (StoredRule $rule, int $index): bool => $rule->key === $stored[$index]->key
            && $rule->version === $stored[$index]->version
            && $rule->params->toArray() === $stored[$index]->params->toArray());
    }

    /** @param  list<ChannelId>  $channels */
    private function insertChannels(string $promotion, array $channels): int
    {
        $now = PgTimestamp::now();

        return $this->db()->table($this->tables->table('promotion_channels'))->insertOrIgnore(array_map(
            static fn (ChannelId $channel): array => [
                'promotion_id' => $promotion,
                'channel_id' => $channel->value,
                'created_at' => $now,
            ],
            $channels,
        ));
    }

    /**
     * @param  'condition'|'effect'  $role
     * @param  list<StoredRule>  $rules
     */
    private function insertRules(string $promotion, string $role, array $rules): void
    {
        if ($rules === []) {
            return;
        }

        $rows = [];

        foreach ($rules as $position => $rule) {
            $rows[] = [
                'promotion_id' => $promotion,
                'role' => $role,
                'position' => $position,
                'rule_key' => $rule->key,
                'config_version' => $rule->version,
                'config' => json_encode($rule->params->toArray(), JSON_THROW_ON_ERROR),
            ];
        }

        $this->db()->table($this->tables->table('promotion_rules'))->insert($rows);
    }

    /** @param  array<string, mixed>  $changes */
    private function touch(PromotionId $id, array $changes = []): void
    {
        $this->db()->table($this->tables->table('promotions'))
            ->where('id', $id->value)
            ->update($changes + ['updated_at' => PgTimestamp::now()]);
    }

    private function lock(PromotionId $id): object
    {
        $row = $this->db()->table($this->tables->table('promotions'))
            ->where('id', $id->value)
            ->lock('for update')
            ->first();

        return $row ?? throw new PromotionNotFound($id->value);
    }

    private function lockActive(PromotionId $id): object
    {
        $row = $this->lock($id);

        if (Rows::string($row, 'status') === PromotionStatus::Archived->value) {
            throw new PromotionArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    /**
     * @param  list<ChannelId>  $channels
     * @return list<ChannelId> without repeats, in input order
     */
    private static function unique(array $channels): array
    {
        $unique = [];

        foreach ($channels as $channel) {
            $unique[$channel->value] ??= $channel;
        }

        return array_values($unique);
    }

    private static function date(object $row, string $column): ?DateTimeImmutable
    {
        $value = Rows::nullableString($row, $column);

        return $value === null ? null : new DateTimeImmutable($value);
    }

    private static function sameInstant(?DateTimeImmutable $a, ?DateTimeImmutable $b): bool
    {
        return $a instanceof DateTimeImmutable && $b instanceof DateTimeImmutable
            ? $a->format('U.u') === $b->format('U.u')
            : $a === $b;
    }

    /** The text of a timestamp as the `timestamptz(6)` columns take it; a missing bound stays open. */
    private static function timestamp(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private function assertCampaignUsable(CampaignId $campaignId, ?Currency $promotionCurrency): void
    {
        $db = $this->db();
        $query = $db->table($this->tables->table('campaigns'))->where('id', $campaignId->value);

        if ($db instanceof Connection && $db->getDriverName() === 'pgsql') {
            $query->lock('for key share');
        }

        $campaign = $query->first(['code', 'status', 'currency']) ?? throw new CampaignNotFound($campaignId->value);

        if (Rows::string($campaign, 'status') === CampaignStatus::Archived->value) {
            throw new CampaignArchived(Rows::string($campaign, 'code'));
        }

        $campaignCurrency = Rows::nullableString($campaign, 'currency');

        if ($campaignCurrency !== null && $promotionCurrency instanceof Currency && $campaignCurrency !== $promotionCurrency->code) {
            throw new InvalidCommand('campaignId', "campaign currency {$campaignCurrency} differs from promotion currency {$promotionCurrency->code}", 'promotions.currency_mismatch');
        }
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

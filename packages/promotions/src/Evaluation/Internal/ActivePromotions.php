<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Context\CommerceContext;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Campaigns\Enums\CampaignStatus;
use Commerz\Promotions\Campaigns\Internal\CampaignBudgetLedger;
use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Enums\PromotionStatus;
use Commerz\Promotions\Definitions\Enums\Stacking;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\ActivationResult;
use Commerz\Promotions\Rules\Data\PromotionSummary;
use Commerz\Promotions\Rules\Data\RuleParams;
use Commerz\Promotions\Rules\Data\RuleScope;
use Commerz\Promotions\Rules\Enums\PromotionStage;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleConfig;
use Commerz\Promotions\Rules\Exceptions\InvalidRuleResult;
use Commerz\Promotions\Rules\Internal\LoadedRule;
use Commerz\Promotions\Rules\Internal\RuleCodec;
use Commerz\Promotions\Rules\Internal\StoredRule;
use Commerz\Quotes\Data\Rejection;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;
use RuntimeException;

/**
 * Builds the session of one quote: the promotions live for its context with their rules and counters (three reads,
 * a fourth for the customer's usage), then every activator asked once about its candidates and the presented codes.
 * Reads only. An activator's answer is checked against what it was asked; one that cannot be trusted fails the quote.
 */
#[Internal]
final readonly class ActivePromotions
{
    private const string SOURCE = 'promotions.catalog';

    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private RuleCodec $codec,
        private Contributions $contributions,
        private PromotionsOptions $promotionsConfig,
    ) {}

    /**
     * @param  list<string>  $codes  the presented codes, trimmed and without repeats
     *
     * @throws InvalidRuleResult an activator answered about a promotion or a code it was not asked about
     */
    public function load(CommerceContext $context, array $codes): PromotionSession
    {
        $candidates = $this->candidates($context);
        $presented = array_slice($codes, 0, $this->promotionsConfig->maxCodes);
        $answers = [];
        $claims = []; // [code, activator key] in the order the activators answered: the first claim of a code wins

        foreach ($this->activatorKeys($candidates, $presented) as $key) {
            $own = array_values(array_filter($candidates, static fn (CandidatePromotion $c): bool => $c->summary->activator === $key));

            if (! $this->contributions->has(PromotionActivator::KIND, $key)) {
                foreach ($own as $candidate) {
                    $candidates[$candidate->summary->id->value] = $candidate->withUnsupportedActivator("no activator contributes '{$key}'");
                }

                continue;
            }

            $activator = $this->contributions->get(PromotionActivator::KIND, $key, PromotionActivator::class);
            $result = $activator->activate(new ActivationRequest(
                $context,
                $presented,
                array_map(static fn (CandidatePromotion $c): PromotionSummary => $c->summary, $own),
            ));

            self::assertTrusted($key, $result, $own, $presented);
            $answers[$key] = $result;

            foreach ($result->activations as $activation) {
                if ($activation->presentedCode !== null) {
                    $claims[] = [$activation->presentedCode, $key];
                }
            }

            foreach ($result->rejections as $rejection) {
                $claims[] = [$rejection->code, $key];
            }
        }

        $owner = [];

        foreach ($claims as [$code, $key]) {
            if (! in_array($code, array_column($owner, 0), true)) {
                $owner[] = [$code, $key];
            }
        }

        $ownerOf = static function (string $code) use ($owner): ?string {
            foreach ($owner as [$claimed, $key]) {
                if ($claimed === $code) {
                    return $key;
                }
            }

            return null;
        };

        $rejections = [];

        foreach ($answers as $key => $result) {
            foreach ($result->activations as $activation) {
                if ($activation->presentedCode === null || $ownerOf($activation->presentedCode) === $key) {
                    $candidates[$activation->promotion->value] = $candidates[$activation->promotion->value]->activated($activation);
                }
            }
        }

        foreach ($codes as $index => $code) {
            if ($index >= $this->promotionsConfig->maxCodes) {
                $rejections[] = new Rejection(self::SOURCE, 'too_many_codes', "At most {$this->promotionsConfig->maxCodes} codes are taken at once.", code: $code);

                continue;
            }

            $key = $ownerOf($code);

            if ($key === null) {
                $rejections[] = new Rejection(self::SOURCE, 'unknown_code', "Code '{$code}' is not known.", code: $code);

                continue;
            }

            foreach ($answers[$key]->rejections as $rejection) {
                if ($rejection->code === $code) {
                    $rejections[] = new Rejection(self::SOURCE, $rejection->reason, $rejection->message, $rejection->promotion?->value, $code);
                }
            }
        }

        $byStage = [];

        foreach ($candidates as $candidate) {
            $byStage[$candidate->summary->stage->value][] = $candidate;
        }

        return new PromotionSession($byStage, $rejections);
    }

    /**
     * The promotions live for the context, in stage, priority DESC, code ASC order (a total order: codes are unique).
     *
     * @return array<string, CandidatePromotion> by promotion id (a UUID, never an integer-like key)
     */
    private function candidates(CommerceContext $context): array
    {
        $at = $context->at->format('Y-m-d H:i:s.uP');
        $promotions = $this->tables->table('promotions');

        $rows = array_values($this->db()->table($promotions.' as p')
            ->where('p.status', PromotionStatus::Active->value)
            ->where(static fn (Builder $q) => $q->whereNull('p.starts_at')->orWhere('p.starts_at', '<=', $at))
            ->where(static fn (Builder $q) => $q->whereNull('p.ends_at')->orWhere('p.ends_at', '>', $at))
            ->where(static fn (Builder $q) => $q->whereNull('p.currency')->orWhere('p.currency', $context->currency->code))
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')
                ->from($this->tables->table('promotion_channels').' as pc')
                ->whereColumn('pc.promotion_id', 'p.id')
                ->where('pc.channel_id', $context->channel->value))
            ->orderBy('p.stage')
            ->orderByDesc('p.priority')
            ->orderBy('p.code')
            ->get(['p.id', 'p.code', 'p.name', 'p.stage', 'p.activator', 'p.stacking', 'p.stop_further', 'p.priority', 'p.currency',
                'p.budget_minor', 'p.spent_minor', 'p.usage_limit', 'p.per_customer_limit', 'p.used', 'p.campaign_id'])
            ->all());

        if ($rows === []) {
            return [];
        }

        $rules = $this->rules($rows);
        $customerUsed = $this->customerUsage($context, $rows);
        $campaigns = $this->campaigns($context, $rows);
        $candidates = [];

        foreach ($rows as $row) {
            $id = Rows::string($row, 'id');
            $currencyCode = Rows::nullableString($row, 'currency');
            $currency = $currencyCode === null ? null : Currency::of($currencyCode);
            $budget = Rows::nullableInt($row, 'budget_minor');
            $campaignId = property_exists($row, 'campaign_id') ? Rows::nullableString($row, 'campaign_id') : null;
            $campaign = $campaignId !== null ? ($campaigns[$campaignId] ?? null) : null;

            $candidates[$id] = new CandidatePromotion(
                new PromotionSummary(
                    new PromotionId($id),
                    Rows::string($row, 'code'),
                    Rows::string($row, 'name'),
                    PromotionStage::from(Rows::string($row, 'stage')),
                    Rows::string($row, 'activator'),
                    Rows::int($row, 'priority'),
                    $currency,
                ),
                Stacking::from(Rows::string($row, 'stacking')),
                self::bool($row, 'stop_further'),
                Rows::nullableInt($row, 'usage_limit'),
                Rows::nullableInt($row, 'per_customer_limit'),
                Rows::int($row, 'used'),
                $customerUsed[$id] ?? 0,
                $budget === null || ! $currency instanceof Currency ? null : Money::ofMinor($budget, $currency),
                $budget === null || ! $currency instanceof Currency ? null : Money::ofMinor(Rows::int($row, 'spent_minor'), $currency),
                $rules[$id][RuleCodec::CONDITION] ?? [],
                $rules[$id][RuleCodec::EFFECT] ?? [],
                campaignId: $campaignId,
                campaignActive: $campaign === null ? $campaignId === null : $campaign['active'],
                campaignBudget: $campaign['budget'] ?? null,
            );
        }

        return $candidates;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, array{active: bool, budget: CampaignBudget}>
     */
    private function campaigns(CommerceContext $context, array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $campaignId = property_exists($row, 'campaign_id') ? Rows::nullableString($row, 'campaign_id') : null;

            if ($campaignId !== null) {
                $ids[$campaignId] = $campaignId;
            }
        }

        if ($ids === []) {
            return [];
        }

        $loaded = [];

        foreach ($this->db()->table($this->tables->table('campaigns'))
            ->whereIn('id', array_values($ids))
            ->get(['id', 'status', 'starts_at', 'ends_at', 'currency', 'spend_cap_minor', 'usage_cap', 'spent_minor', 'used'])
            ->all() as $row) {
            $startsAt = Rows::nullableString($row, 'starts_at');
            $endsAt = Rows::nullableString($row, 'ends_at');
            $currency = Rows::nullableString($row, 'currency');
            $active = Rows::string($row, 'status') === CampaignStatus::Active->value
                && ($startsAt === null || new DateTimeImmutable($startsAt) <= $context->at)
                && ($endsAt === null || new DateTimeImmutable($endsAt) > $context->at)
                && ($currency === null || $currency === $context->currency->code);

            $loaded[Rows::string($row, 'id')] = [
                'active' => $active,
                'budget' => CampaignBudgetLedger::budgetFromRow($row),
            ];
        }

        return $loaded;
    }

    /**
     * @param  list<object>  $promotions  promotion rows with their stage and currency
     * @return array<string, array<string, list<LoadedRule>>> by promotion id and role, in declared order
     */
    private function rules(array $promotions): array
    {
        $scopes = [];

        foreach ($promotions as $promotion) {
            $currency = Rows::nullableString($promotion, 'currency');
            $scopes[Rows::string($promotion, 'id')] = new RuleScope(
                PromotionStage::from(Rows::string($promotion, 'stage')),
                $currency === null ? null : Currency::of($currency),
            );
        }

        $rows = $this->db()->table($this->tables->table('promotion_rules'))
            ->whereIn('promotion_id', array_keys($scopes))
            ->orderBy('promotion_id')
            ->orderBy('role')
            ->orderBy('position')
            ->get(['promotion_id', 'role', 'rule_key', 'config_version', 'config'])
            ->all();

        $rules = [];

        foreach ($rows as $row) {
            $role = Rows::string($row, 'role') === RuleCodec::EFFECT ? RuleCodec::EFFECT : RuleCodec::CONDITION;
            $key = Rows::string($row, 'rule_key');

            try {
                $loaded = $this->codec->decode(new StoredRule($key, Rows::int($row, 'config_version'), RuleParams::fromInput(Rows::json($row, 'config'))), $role, $scopes[Rows::string($row, 'promotion_id')]);
            } catch (InvalidRuleConfig|RuntimeException $e) {
                $loaded = LoadedRule::unsupported($key, "stored parameters cannot be read: {$e->getMessage()}");
            }

            $rules[Rows::string($row, 'promotion_id')][$role][] = $loaded;
        }

        return $rules;
    }

    /**
     * Orders of the quote's customer per promotion, for the promotions with a per-customer limit.
     *
     * @param  list<object>  $rows
     * @return array<string, int> by promotion id
     */
    private function customerUsage(CommerceContext $context, array $rows): array
    {
        $limited = array_values(array_map(
            static fn (object $row): string => Rows::string($row, 'id'),
            array_filter($rows, static fn (object $row): bool => Rows::nullableInt($row, 'per_customer_limit') !== null),
        ));

        if ($context->customer === null || $limited === []) {
            return [];
        }

        $used = [];

        foreach ($this->db()->table($this->tables->table('promotion_customer_usages'))
            ->where('customer_ref', (string) $context->customer)
            ->whereIn('promotion_id', $limited)
            ->get(['promotion_id', 'used'])
            ->all() as $row) {
            $used[Rows::string($row, 'promotion_id')] = Rows::int($row, 'used');
        }

        return $used;
    }

    /**
     * Activators of the candidates, and every contributed one when codes are presented (a code of a promotion that is
     * not live is then refused by its activator rather than reported unknown), in key order.
     *
     * @param  array<string, CandidatePromotion>  $candidates
     * @param  list<string>  $codes
     * @return list<string>
     */
    private function activatorKeys(array $candidates, array $codes): array
    {
        $keys = array_map(static fn (CandidatePromotion $c): string => $c->summary->activator, array_values($candidates));

        if ($codes !== []) {
            $keys = [...$keys, ...$this->contributions->keys(PromotionActivator::KIND)];
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @param  list<CandidatePromotion>  $asked
     * @param  list<string>  $codes
     *
     * @throws InvalidRuleResult
     */
    private static function assertTrusted(string $key, ActivationResult $result, array $asked, array $codes): void
    {
        $askedIds = array_map(static fn (CandidatePromotion $c): string => $c->summary->id->value, $asked);
        $activated = [];

        foreach ($result->activations as $activation) {
            $id = $activation->promotion->value;

            if (! in_array($id, $askedIds, true)) {
                throw new InvalidRuleResult($key, "promotion '{$id}' was not one of its candidates");
            }

            if (in_array($id, $activated, true)) {
                throw new InvalidRuleResult($key, "promotion '{$id}' is switched on twice");
            }

            if ($activation->presentedCode !== null && ! in_array($activation->presentedCode, $codes, true)) {
                throw new InvalidRuleResult($key, "code '{$activation->presentedCode}' was not presented");
            }

            $activated[] = $id;
        }

        foreach ($result->rejections as $rejection) {
            if (! in_array($rejection->code, $codes, true)) {
                throw new InvalidRuleResult($key, "code '{$rejection->code}' was not presented");
            }
        }
    }

    private static function bool(object $row, string $column): bool
    {
        $value = get_object_vars($row)[$column] ?? null;

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        return Rows::bool($row, $column);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

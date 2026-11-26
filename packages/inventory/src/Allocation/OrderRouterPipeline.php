<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation;

use Commerz\Identity\SellableRef;
use Commerz\Inventory\Allocation\Contracts\OrderRouter;
use Commerz\Inventory\Allocation\Data\AllocationNeed;
use Commerz\Inventory\Allocation\Data\AllocationPlan;
use Commerz\Inventory\Allocation\Data\AllocationRequest;
use Commerz\Inventory\Allocation\Data\OrderRoutingRequest;
use Commerz\Inventory\Allocation\Data\OrderRoutingResult;
use Commerz\Inventory\Allocation\Data\SourceStock;
use Commerz\Inventory\Allocation\Internal\AllocationPlanValidator;
use Commerz\Inventory\Allocation\Internal\Placement;
use Commerz\Inventory\Allocation\Internal\PriorityAllocation;
use Commerz\Inventory\Allocation\Internal\SingleLocationAllocation;
use Commerz\Inventory\Allocation\Rules\DailyLocationQuotaRule;
use Commerz\Inventory\Allocation\Rules\FulfillmentConstraintsRule;
use Commerz\Inventory\Allocation\Rules\GeoProximityRule;
use Commerz\Inventory\Allocation\Rules\MarketAffinityRule;
use Commerz\Inventory\Allocation\Rules\MinimizeSplitShipmentsRule;
use Commerz\Ports\Delivery\Contracts\OrderRoutingRule;
use Commerz\Ports\Delivery\Data\FulfillmentAssignment;
use Commerz\Ports\Delivery\Data\FulfillmentAssignmentLine;
use Commerz\Ports\Delivery\Data\FulfillmentConstraints;
use Commerz\Ports\Delivery\Data\RoutingCandidate;
use Override;
use Packman\Attributes\Api;

/**
 * Pure, composable order-routing pipeline. Always enforces {@see FulfillmentConstraints} before running
 * configured {@see OrderRoutingRule} stages, validates the resulting {@see AllocationPlan}, and groups
 * allocations into per-location {@see FulfillmentAssignment}s.
 */
#[Api(since: '0.1')]
final readonly class OrderRouterPipeline implements OrderRouter
{
    /** @param  list<OrderRoutingRule>  $rules */
    public function __construct(private array $rules = []) {}

    /** Default pipeline: constraints, daily quota, minimize splits, geo-proximity, and market affinity. */
    public static function default(): self
    {
        return new self([
            new FulfillmentConstraintsRule,
            new DailyLocationQuotaRule,
            new MinimizeSplitShipmentsRule,
            new GeoProximityRule,
            new MarketAffinityRule,
        ]);
    }

    public function withRule(OrderRoutingRule $rule): self
    {
        return new self([...$this->rules, $rule]);
    }

    /** @return list<OrderRoutingRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    #[Override]
    public function route(OrderRoutingRequest $request): OrderRoutingResult
    {
        $allocation = $request->allocation;
        $context = $request->context;
        $constraints = $context->constraints;

        $sourcesByCode = [];
        $candidates = [];

        foreach ($allocation->sources as $source) {
            $profile = $context->profileFor($source->locationCode);

            if (! $profile->satisfies($constraints)) {
                continue;
            }

            $sourcesByCode[$source->locationCode] = $source;
            [$canFulfillAll, $fulfillableUnits, $fulfillableLineCount] = self::coverage($allocation->needs, $source);

            $candidates[] = new RoutingCandidate(
                locationCode: $source->locationCode,
                priority: $source->priority,
                profile: $profile,
                canFulfillAll: $canFulfillAll,
                fulfillableUnits: $fulfillableUnits,
                fulfillableLineCount: $fulfillableLineCount,
            );
        }

        $appliedRules = [];

        foreach ($this->rules as $rule) {
            $candidates = self::sanitize($rule->apply($candidates, $context), $sourcesByCode);
            $appliedRules[] = $rule->key();
        }

        if ($candidates === []) {
            return new OrderRoutingResult(
                plan: new AllocationPlan,
                assignments: [],
                unplaced: array_map(static fn (AllocationNeed $need): SellableRef => $need->sellable, $allocation->needs),
                appliedRules: $appliedRules,
            );
        }

        $orderedSources = array_map(
            static fn (RoutingCandidate $candidate): SourceStock => $sourcesByCode[$candidate->locationCode],
            $candidates,
        );

        $routedRequest = new AllocationRequest($allocation->channel, $allocation->needs, $orderedSources);
        $useSingleLocation = $constraints->mustShipTogether || $candidates[0]->canFulfillAll;
        $strategy = $useSingleLocation ? new SingleLocationAllocation : new PriorityAllocation;
        $plan = $strategy->allocate($routedRequest);

        (new AllocationPlanValidator)->validate($routedRequest, $plan);

        $placedUnits = [];

        foreach ($plan->allocations as $planned) {
            $key = (string) $planned->sellable;
            $placedUnits[$key] = ($placedUnits[$key] ?? 0) + $planned->units;
        }

        $unplaced = [];

        foreach ($allocation->needs as $need) {
            if (($placedUnits[(string) $need->sellable] ?? 0) < $need->units) {
                $unplaced[] = $need->sellable;
            }
        }

        return new OrderRoutingResult(
            plan: $plan,
            assignments: self::buildAssignments($plan, $orderedSources, $constraints),
            unplaced: $unplaced,
            appliedRules: $appliedRules,
        );
    }

    /**
     * Evaluates whether `$source` alone can fulfill every need and counts how many lines/units it covers.
     *
     * @param  list<AllocationNeed>  $needs
     * @return array{0: bool, 1: int, 2: int}
     */
    private static function coverage(array $needs, SourceStock $source): array
    {
        $canFulfillAll = self::canFulfillAll($needs, $source);
        $free = $source->free;
        $fulfillableUnits = 0;
        $fulfillableLines = 0;

        foreach ($needs as $need) {
            $fitting = Placement::fitting($need->components, $free, backorderToo: false);
            $covered = $fitting === null ? $need->units : min($fitting, $need->units);

            if ($covered >= $need->units) {
                $fulfillableLines++;
            }

            if ($covered > 0) {
                $fulfillableUnits = Placement::sum($fulfillableUnits, $covered);
                $free = Placement::take($need->components, $free, $covered, backorderToo: false);
            }
        }

        return [$canFulfillAll, $fulfillableUnits, $fulfillableLines];
    }

    /** @param  list<AllocationNeed>  $needs */
    private static function canFulfillAll(array $needs, SourceStock $source): bool
    {
        foreach ([true, false] as $backorderToo) {
            $free = $source->free;
            $fitsAll = true;

            foreach ($needs as $need) {
                $fitting = Placement::fitting($need->components, $free, $backorderToo);

                if ($fitting !== null && $fitting < $need->units) {
                    $fitsAll = false;
                    break;
                }

                $free = Placement::take($need->components, $free, $need->units, $backorderToo);
            }

            if ($fitsAll) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keeps only eligible candidates (preventing a rule from introducing unknown or constraint-violating locations)
     * and preserves first-seen order without duplicates.
     *
     * @param  list<RoutingCandidate>  $candidates
     * @param  array<string, SourceStock>  $eligible
     * @return list<RoutingCandidate>
     */
    private static function sanitize(array $candidates, array $eligible): array
    {
        $seen = [];
        $clean = [];

        foreach ($candidates as $candidate) {
            $code = $candidate->locationCode;

            if (! isset($eligible[$code]) || isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;
            $clean[] = $candidate;
        }

        return $clean;
    }

    /**
     * Groups a validated plan into one {@see FulfillmentAssignment} per distinct location in routed order.
     *
     * @param  list<SourceStock>  $orderedSources
     * @return list<FulfillmentAssignment>
     */
    private static function buildAssignments(AllocationPlan $plan, array $orderedSources, FulfillmentConstraints $constraints): array
    {
        $codeByLocationId = [];

        foreach ($orderedSources as $source) {
            $codeByLocationId[(string) $source->location] = $source->locationCode;
        }

        /** @var array<string, list<FulfillmentAssignmentLine>> $grouped */
        $grouped = [];

        foreach ($plan->allocations as $planned) {
            $locationCode = $codeByLocationId[(string) $planned->location] ?? null;

            if ($locationCode === null) {
                continue;
            }

            $grouped[$locationCode][] = new FulfillmentAssignmentLine($planned->sellable, $planned->units);
        }

        $assignments = [];

        foreach ($orderedSources as $source) {
            $lines = $grouped[$source->locationCode] ?? null;

            if ($lines === null || $lines === []) {
                continue;
            }

            $assignments[] = new FulfillmentAssignment($source->locationCode, $lines, $constraints);
        }

        return $assignments;
    }
}

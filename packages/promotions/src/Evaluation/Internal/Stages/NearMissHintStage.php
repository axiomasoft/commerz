<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal\Stages;

use Commerz\Promotions\Configuration\PromotionsOptions;
use Commerz\Promotions\Evaluation\Internal\NearMissEvaluator;
use Commerz\Quotes\Contracts\QuoteStage;
use Commerz\Quotes\Contracts\QuoteStagePolicy;
use Commerz\Quotes\Data\NearMissHint;
use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\FailurePolicy;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;

/**
 * Non-blocking quote stage computing storefront {@see NearMissHint} entries after all promotion
 * stages have run. Uses {@see FailurePolicy::IgnoreOnFailure} so hint evaluation errors or timeouts never crash the
 * quote pipeline.
 */
#[Internal]
final readonly class NearMissHintStage implements QuoteStage, QuoteStagePolicy
{
    public const string STAGE_KEY = 'promotions.near_miss_hints';

    public function __construct(private Container $container) {}

    public function key(): string
    {
        return self::STAGE_KEY;
    }

    public function anchor(): QuoteAnchor
    {
        return QuoteAnchor::DeliveryPromotions;
    }

    public function position(): StagePosition
    {
        return StagePosition::After;
    }

    public function failurePolicy(): FailurePolicy
    {
        return FailurePolicy::IgnoreOnFailure;
    }

    public function timeoutMilliseconds(): int
    {
        return $this->container->make(PromotionsOptions::class)->nearMissTimeoutMs;
    }

    public function apply(QuoteState $state): QuoteState
    {
        $evaluator = $this->container->make(NearMissEvaluator::class);

        foreach ($evaluator->evaluate($state) as $hint) {
            $state = $state->withNearMissHint($hint);
        }

        return $state;
    }
}

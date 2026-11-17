<?php

declare(strict_types=1);

namespace Commerz\Promotions\Evaluation\Internal;

use Commerz\Promotions\Definitions\Data\ExplainPromotions;
use Commerz\Promotions\Definitions\Data\PromotionExplanation;
use Commerz\Quotes\Contracts\QuotePipeline;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Illuminate\Contracts\Container\Container;
use Packman\Attributes\Internal;

/**
 * Runs the quote and reads the verdicts its promotion session recorded. The input's earlier session is dropped first,
 * so a quote that fails before the catalog stage explains nothing rather than an older run.
 */
#[Internal]
final readonly class PromotionExplainer
{
    public function __construct(private Container $container, private PromotionSessions $sessions) {}

    public function explain(ExplainPromotions $query): PromotionExplanation
    {
        $input = $query->input;
        $quote = null;
        $failure = null;
        $this->sessions->forget($input);

        try {
            $quote = $this->container->make(QuotePipeline::class)->run($input);
        } catch (QuoteFailed $e) {
            $failure = "quotes.failed:{$e->stage}:{$e->reason}";
        }

        $session = $this->sessions->find($input);

        return new PromotionExplanation($quote, $failure, $session?->verdicts() ?? [], $session->codeRejections ?? []);
    }
}

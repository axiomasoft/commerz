<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Internal\Activators;

use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\Activation;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\ActivationResult;
use Commerz\Promotions\Rules\Data\PromotionSummary;

/** Switches on every candidate; presented codes are not its business. */
final readonly class AutomaticActivator implements PromotionActivator
{
    public function key(): string
    {
        return 'automatic';
    }

    public function activate(ActivationRequest $request): ActivationResult
    {
        return new ActivationResult(array_map(
            static fn (PromotionSummary $promotion): Activation => new Activation($promotion->id),
            $request->promotions,
        ));
    }
}

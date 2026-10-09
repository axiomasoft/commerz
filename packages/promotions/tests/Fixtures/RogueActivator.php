<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests\Fixtures;

use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Rules\Contracts\PromotionActivator;
use Commerz\Promotions\Rules\Data\Activation;
use Commerz\Promotions\Rules\Data\ActivationRequest;
use Commerz\Promotions\Rules\Data\ActivationResult;

/**
 * Switches promotions on as the presented code says: `FOREIGN` a promotion it was not asked about, `TWICE` its first
 * candidate twice, `GHOST` its first candidate by a code that was not presented, `RIGHT` its first candidate properly.
 */
final class RogueActivator implements PromotionActivator
{
    public function key(): string
    {
        return 'test.rogue';
    }

    public function activate(ActivationRequest $request): ActivationResult
    {
        $first = $request->promotions[0]->id ?? null;
        $activations = [];

        foreach ($request->codes as $code) {
            $activations = [...$activations, ...match (true) {
                $code === 'FOREIGN' => [new Activation(PromotionId::generate(), 'FOREIGN', $code)],
                $first === null => [],
                $code === 'TWICE' => [new Activation($first, 'TWICE', $code), new Activation($first, 'TWICE', $code)],
                $code === 'GHOST' => [new Activation($first, 'GHOST', 'NOT-PRESENTED')],
                $code === 'RIGHT' => [new Activation($first, 'RIGHT', $code)],
                $code === 'EXTERNAL' => [new Activation($first, presentedCode: $code)],
                default => [],
            }];
        }

        return new ActivationResult($activations);
    }
}

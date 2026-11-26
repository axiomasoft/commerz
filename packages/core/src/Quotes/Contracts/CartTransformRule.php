<?php

declare(strict_types=1);

namespace Commerz\Quotes\Contracts;

use Commerz\Quotes\Data\QuoteInput;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Stage 01 (`cart_transform`) rule contributed via `PluginRegistrar::contribute(CartTransformRule::KIND, ...)`.
 * Rules inspect the quote input lines and emit {@see CartTransformOperation} instructions (`LineExpand`,
 * `LinesMerge`, `LineUpdate`). Rules may also implement {@see QuoteStagePolicy}; by default they use
 * `FailurePolicy::BlockOnFailure` so a broken bundle/merge transform halts the quote rather than mispricing.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface CartTransformRule
{
    public const string KIND = 'cart_transform_rule';

    public function key(): string;

    /**
     * @return list<CartTransformOperation>
     */
    public function transform(QuoteInput $input): array;
}

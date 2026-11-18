<?php

declare(strict_types=1);

namespace Commerz\Quotes\Contracts;

use Commerz\Quotes\Data\QuoteState;
use Commerz\Quotes\Enums\QuoteAnchor;
use Commerz\Quotes\Enums\StagePosition;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * One step of the quote pipeline, registered as the `quote_stage` contribution (`PluginRegistrar::contribute(QuoteStage::KIND, ...)`).
 * Stages run by anchor, then `Before → At → After`, then key. A stage is a pure function of the state: it may read
 * (prices, rules) but never writes to the database or calls the network, and takes the time from the context.
 * Any exception it throws fails the quote with `QuoteFailed`.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface QuoteStage
{
    public const string KIND = 'quote_stage';

    public function key(): string;

    public function anchor(): QuoteAnchor;

    public function position(): StagePosition;

    public function apply(QuoteState $state): QuoteState;
}

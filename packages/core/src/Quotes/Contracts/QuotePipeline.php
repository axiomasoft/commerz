<?php

declare(strict_types=1);

namespace Commerz\Quotes\Contracts;

use Commerz\Errors\InvalidConfiguration;
use Commerz\Quotes\Data\QuoteInput;
use Commerz\Quotes\Data\QuoteResult;
use Commerz\Quotes\Exceptions\QuoteFailed;
use Packman\Attributes\Api;

/**
 * Prices a set of lines: runs every registered `QuoteStage` in anchor order. Pure calculation: it writes nothing,
 * opens no transaction, and the same input with the same stages gives the same result and fingerprint.
 */
#[Api(since: '0.1')]
interface QuotePipeline
{
    /**
     * @throws QuoteFailed a stage failed or the totals are invalid (negative)
     * @throws InvalidConfiguration the registered stages conflict
     */
    public function run(QuoteInput $input): QuoteResult;
}

<?php

declare(strict_types=1);

namespace Commerz\B2b\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Money\Money;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class StagedLineOverride
{
    public function __construct(
        public string $lineId,
        public ?Money $negotiatedUnitPrice = null,
        public ?int $quantity = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'b2b.invalid_quote_line');
        }

        if ($negotiatedUnitPrice !== null && $negotiatedUnitPrice->isNegative()) {
            throw new InvalidCommand('negotiatedUnitPrice', 'must not be negative', 'b2b.invalid_quote_line');
        }

        if ($quantity !== null && $quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'b2b.invalid_quote_line');
        }
    }
}

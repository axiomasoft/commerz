<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Identifies an input line and how many units of it are merged by {@see LinesMerge}.
 * When `$quantity` is null, the entire line is consumed into the merged parent line.
 */
#[Api(since: '0.1')]
final readonly class MergeTargetLine
{
    public function __construct(
        public string $lineId,
        public ?int $quantity = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'quotes.invalid_transform');
        }

        if ($quantity !== null && $quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1 when specified', 'quotes.invalid_transform');
        }
    }
}

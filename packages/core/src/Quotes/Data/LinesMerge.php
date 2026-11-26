<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Quotes\Contracts\CartTransformOperation;
use Packman\Attributes\Api;

/**
 * Merges one or more cart lines (or partial quantities of them) into a single parent bundle line.
 */
#[Api(since: '0.1')]
final readonly class LinesMerge implements CartTransformOperation
{
    /** @var list<MergeTargetLine> */
    public array $targets;

    /**
     * @param  list<MergeTargetLine|string>  $targets
     */
    public function __construct(
        array $targets,
        public SellableSnapshot|SellableRef $parentSellable,
        public int $quantity = 1,
        public ?Money $fixedUnitPrice = null,
        public ?bool $taxIncluded = null,
        public ?string $lineId = null,
    ) {
        if ($targets === []) {
            throw new InvalidCommand('targets', 'must contain at least one target line', 'quotes.invalid_transform');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'quotes.invalid_transform');
        }

        if ($fixedUnitPrice !== null && $fixedUnitPrice->isNegative()) {
            throw new InvalidCommand('fixedUnitPrice', 'must not be negative', 'quotes.invalid_transform');
        }

        if ($lineId !== null && preg_match('/\A[A-Za-z0-9_.:\-]{1,64}\z/', $lineId) !== 1) {
            throw new InvalidCommand('lineId', "'{$lineId}' must match [A-Za-z0-9_.:-]{1,64}", 'quotes.invalid_transform');
        }

        $normalized = [];
        $seen = [];

        foreach ($targets as $target) {
            $item = is_string($target) ? new MergeTargetLine($target) : $target;

            if (isset($seen[$item->lineId])) {
                throw new InvalidCommand('targets', "line '{$item->lineId}' is targeted more than once in the same merge", 'quotes.invalid_transform');
            }

            $seen[$item->lineId] = true;
            $normalized[] = $item;
        }

        $this->targets = $normalized;
    }
}

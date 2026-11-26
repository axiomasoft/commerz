<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Data;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Replaces all relations of a given type for a source product in the given order. */
#[Api(since: '0.1')]
final readonly class ReplaceProductRelations
{
    /** @param  list<ProductId>  $targets */
    public function __construct(
        public ProductId $sourceProduct,
        public ProductRelationType $type,
        public array $targets,
    ) {
        $seen = [];

        foreach ($targets as $target) {
            if ($sourceProduct->equals($target)) {
                throw new InvalidCommand('targets', 'a product cannot relate to itself');
            }

            if (isset($seen[$target->value])) {
                throw new InvalidCommand('targets', "duplicate target product '{$target->value}'");
            }

            $seen[$target->value] = true;
        }
    }
}

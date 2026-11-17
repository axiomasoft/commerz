<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Packman\Attributes\Api;

/** Outcome of `generate`: every planned combination is either created or skipped (it existed); a dry run creates none. */
#[Api(since: '0.1')]
final readonly class GeneratedVariants
{
    /**
     * @param  list<PlannedVariant>  $planned  every combination of the command, in generation order
     * @param  list<VariantView>  $created
     * @param  list<array<string, string>>  $skipped  combinations the product already had
     */
    public function __construct(
        public array $planned,
        public array $created,
        public array $skipped,
        public bool $dryRun,
    ) {}
}

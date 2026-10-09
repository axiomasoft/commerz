<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Variants\Data\PlannedVariant;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;

/** The combinations of one generation, split into those the product lacks and those it already has. */
final class GenerationPlan
{
    /** @var list<PlannedVariant> */
    private array $planned = [];

    /** @var list<array<string, string>> */
    private array $skipped = [];

    /** @var array<string, array{id: string, values: array<string, string>, variant: PlannedVariant}> signature => new variant */
    private array $missing = [];

    /** @var array<string, true> */
    private array $skus = [];

    /** @param  array<string, string>  $values  option id => option value id */
    public function add(string $signature, array $values, PlannedVariant $variant, bool $exists): void
    {
        if (isset($this->skus[$variant->sku])) {
            throw new InvalidCommand('skuPattern', "gives the SKU '{$variant->sku}' to two combinations", 'catalog.invalid_sku_pattern');
        }

        $this->skus[$variant->sku] = true;
        $this->planned[] = $variant;

        if ($exists) {
            $this->skipped[] = $variant->axes;
        } else {
            $this->missing[$signature] = ['id' => Uuid7::generate(), 'values' => $values, 'variant' => $variant];
        }
    }

    /** @return list<PlannedVariant> */
    public function planned(): array
    {
        return $this->planned;
    }

    /** @return list<array<string, string>> */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return array<string, array{id: string, values: array<string, string>, variant: PlannedVariant}> */
    public function missing(): array
    {
        return $this->missing;
    }
}

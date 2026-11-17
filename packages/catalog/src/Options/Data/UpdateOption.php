<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Renames an option or relabels its values; codes are immutable. */
#[Api(since: '0.1')]
final readonly class UpdateOption
{
    /** @param  array<array-key, LocalizedText>  $valueLabels  value code => new label */
    public function __construct(
        public OptionId $option,
        public ?LocalizedText $name = null,
        public array $valueLabels = [],
    ) {
        foreach ($valueLabels as $code => $label) {
            CatalogCodes::code('valueLabels', (string) $code);
        }
    }
}

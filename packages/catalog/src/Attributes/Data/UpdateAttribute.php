<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Changes name, filterable, status or choice labels; type, config and choice codes are immutable. */
#[Api(since: '0.1')]
final readonly class UpdateAttribute
{
    /** @param  array<array-key, LocalizedText>  $choiceLabels  choice code => new label */
    public function __construct(
        public AttributeId $attribute,
        public ?LocalizedText $name = null,
        public ?bool $filterable = null,
        public ?AttributeStatus $status = null,
        public array $choiceLabels = [],
    ) {
        foreach ($choiceLabels as $code => $label) {
            CatalogCodes::code('choiceLabels', (string) $code);
        }
    }
}

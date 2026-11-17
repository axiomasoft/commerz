<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Packman\Attributes\Api;

/** What an attribute type needs to know about a definition: its code, canonical config and choices. */
#[Api(since: '0.1')]
final readonly class AttributeDefinition
{
    /**
     * @param  array<string, mixed>  $config  already canonical
     * @param  list<AttributeChoiceView>  $choices
     */
    public function __construct(
        public string $code,
        public array $config,
        public array $choices = [],
    ) {}

    public function choice(string $code): ?AttributeChoiceView
    {
        foreach ($this->choices as $choice) {
            if ($choice->code === $code) {
                return $choice;
            }
        }

        return null;
    }
}

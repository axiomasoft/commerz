<?php

declare(strict_types=1);

namespace Commerz\Catalog\Collections\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UpdateCollection
{
    public function __construct(
        public CollectionId $collection,
        public ?LocalizedText $name = null,
        public ?LocalizedText $description = null,
        public bool $clearDescription = false,
        public ?SmartCollection $smart = null,
    ) {
        if ($description instanceof LocalizedText && $clearDescription) {
            throw new InvalidCommand('description', 'cannot both set and clear description');
        }
    }
}

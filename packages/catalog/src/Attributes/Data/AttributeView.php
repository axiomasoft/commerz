<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Data;

use Commerz\Attributes\Api;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Measures\LocalizedText;
use DateTimeImmutable;

#[Api(since: '0.1')]
final readonly class AttributeView
{
    /**
     * @param  array<string, mixed>  $config  canonical config of the type
     * @param  bool  $supported  whether an installed plugin still contributes the type
     * @param  list<AttributeChoiceView>  $choices  in position order
     */
    public function __construct(
        public AttributeId $id,
        public string $code,
        public string $type,
        public LocalizedText $name,
        public array $config,
        public int $configVersion,
        public bool $filterable,
        public AttributeStatus $status,
        public bool $supported,
        public array $choices,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}

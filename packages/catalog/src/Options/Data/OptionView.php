<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Data;

use Commerz\Measures\LocalizedText;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class OptionView
{
    /** @param  list<OptionValueView>  $values  in position order */
    public function __construct(
        public OptionId $id,
        public string $code,
        public LocalizedText $name,
        public array $values,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}

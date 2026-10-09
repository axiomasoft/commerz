<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Data;

use Commerz\Attributes\Api;

/** Archives a variant; its combination stays taken. Archiving an archived variant changes nothing. */
#[Api(since: '0.1')]
final readonly class ArchiveVariant
{
    public function __construct(public VariantId $variant) {}
}

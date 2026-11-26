<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Data;

use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Catalog\Products\Data\ProductId;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class ListProductAssets
{
    public function __construct(
        public ProductId $product,
        public ?AssetRole $role = null,
        public ?OptionValueId $optionValue = null,
    ) {}
}

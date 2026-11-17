<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Changes the given fields; a null field stays as it is. Status moves only between draft and active. */
#[Api(since: '0.1')]
final readonly class UpdateProduct
{
    public function __construct(
        public ProductId $product,
        public ?string $handle = null,
        public ?LocalizedText $name = null,
        public ?LocalizedText $description = null,
        public ?ProductStatus $status = null,
    ) {
        if ($handle !== null) {
            CatalogCodes::handle($handle);
        }

        if ($status === ProductStatus::Archived) {
            throw new InvalidCommand('status', 'archive a product with ArchiveProduct', 'catalog.invalid_status_transition');
        }
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Data;

use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Attaches a media asset to a product, optionally scoped to an option value. */
#[Api(since: '0.1')]
final readonly class AttachAsset
{
    public function __construct(
        public ProductId $product,
        public string $assetId,
        public AssetRole $role,
        public ?LocalizedText $alt = null,
        public int $position = 0,
        public ?OptionValueId $optionValue = null,
    ) {
        if (trim($assetId) === '' || strlen($assetId) > 191) {
            throw new InvalidCommand('assetId', 'must be 1 to 191 bytes');
        }

        if ($position < 0) {
            throw new InvalidCommand('position', 'must not be negative');
        }
    }
}

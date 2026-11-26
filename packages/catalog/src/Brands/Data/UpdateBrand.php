<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Updates the given fields of a brand; null fields stay unchanged unless their clear flag is set. */
#[Api(since: '0.1')]
final readonly class UpdateBrand
{
    public function __construct(
        public BrandId $brand,
        public ?LocalizedText $name = null,
        public ?LocalizedText $description = null,
        public bool $clearDescription = false,
        public ?string $logoAssetId = null,
        public bool $clearLogoAssetId = false,
        public ?string $websiteUrl = null,
        public bool $clearWebsiteUrl = false,
    ) {
        if ($logoAssetId !== null && (trim($logoAssetId) === '' || strlen($logoAssetId) > 191)) {
            throw new InvalidCommand('logoAssetId', 'must be 1 to 191 bytes');
        }

        if ($websiteUrl !== null && (trim($websiteUrl) === '' || strlen($websiteUrl) > 500 || filter_var($websiteUrl, FILTER_VALIDATE_URL) === false)) {
            throw new InvalidCommand('websiteUrl', 'must be a valid URL up to 500 bytes');
        }
    }
}

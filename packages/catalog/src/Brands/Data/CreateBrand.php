<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Data;

use Commerz\Catalog\Common\Data\CatalogCodes;
use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Packman\Attributes\Api;

/** Creates a brand. */
#[Api(since: '0.1')]
final readonly class CreateBrand
{
    public function __construct(
        public string $code,
        public LocalizedText $name,
        public ?LocalizedText $description = null,
        public ?string $logoAssetId = null,
        public ?string $websiteUrl = null,
    ) {
        CatalogCodes::code('code', $code);

        if ($logoAssetId !== null && (trim($logoAssetId) === '' || strlen($logoAssetId) > 191)) {
            throw new InvalidCommand('logoAssetId', 'must be 1 to 191 bytes');
        }

        if ($websiteUrl !== null && (trim($websiteUrl) === '' || strlen($websiteUrl) > 500 || filter_var($websiteUrl, FILTER_VALIDATE_URL) === false)) {
            throw new InvalidCommand('websiteUrl', 'must be a valid URL up to 500 bytes');
        }
    }
}

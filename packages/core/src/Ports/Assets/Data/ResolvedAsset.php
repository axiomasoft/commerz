<?php

declare(strict_types=1);

namespace Commerz\Ports\Assets\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Host-resolved asset metadata and rendition URLs for storefront or administrative display. */
#[Api(since: '0.1')]
final readonly class ResolvedAsset
{
    /** @var array<string, string> */
    public array $variants;

    /**
     * @param  array<string, string>  $variants  named renditions (e.g. 'thumb' => 'https://...')
     */
    public function __construct(
        public AssetRef $ref,
        public string $url,
        public ?string $mimeType = null,
        public ?int $sizeBytes = null,
        public ?int $widthPx = null,
        public ?int $heightPx = null,
        public ?string $altText = null,
        array $variants = [],
    ) {
        if (trim($url) === '' || strlen($url) > 2048 || str_contains($url, "\0")) {
            throw new InvalidCommand('url', 'must be 1..2048 bytes without NUL', 'assets.invalid_asset');
        }

        if ($mimeType !== null && (trim($mimeType) === '' || strlen($mimeType) > 191 || str_contains($mimeType, "\0"))) {
            throw new InvalidCommand('mimeType', 'must be 1..191 bytes without NUL when set', 'assets.invalid_asset');
        }

        if ($sizeBytes !== null && $sizeBytes < 0) {
            throw new InvalidCommand('sizeBytes', 'must not be negative', 'assets.invalid_asset');
        }

        if ($widthPx !== null && $widthPx < 1) {
            throw new InvalidCommand('widthPx', 'must be positive when set', 'assets.invalid_asset');
        }

        if ($heightPx !== null && $heightPx < 1) {
            throw new InvalidCommand('heightPx', 'must be positive when set', 'assets.invalid_asset');
        }

        $cleanVariants = [];

        foreach ($variants as $name => $variantUrl) {
            if (preg_match('/\A[a-z0-9_.\-]{1,64}\z/', $name) !== 1) {
                throw new InvalidCommand('variants', 'variant key must match [a-z0-9_.-]{1,64}', 'assets.invalid_asset');
            }

            if (trim($variantUrl) === '' || strlen($variantUrl) > 2048 || str_contains($variantUrl, "\0")) {
                throw new InvalidCommand('variants', "variant '{$name}' URL must be 1..2048 bytes without NUL", 'assets.invalid_asset');
            }

            $cleanVariants[$name] = $variantUrl;
        }

        $this->variants = $cleanVariants;
    }
}

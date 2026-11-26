<?php

declare(strict_types=1);

namespace Commerz\Testkit\Fakes;

use Commerz\Ports\Assets\Contracts\AssetResolverPort;
use Commerz\Ports\Assets\Data\AssetRef;
use Commerz\Ports\Assets\Data\ResolvedAsset;
use DateTimeImmutable;
use Override;
use Packman\Attributes\Api;

/**
 * In-memory `AssetResolverPort` for storefront/admin media resolution and signed URL tests.
 */
#[Api(since: '0.1')]
final class FakeAssetResolver implements AssetResolverPort
{
    /** @var array<string, ResolvedAsset> */
    private array $assets = [];

    /** @var array<string, bool> */
    private array $protected = [];

    public function add(ResolvedAsset ...$assets): self
    {
        foreach ($assets as $asset) {
            $this->assets[(string) $asset->ref] = $asset;
        }

        return $this;
    }

    public function markProtected(AssetRef $asset, bool $protected = true): self
    {
        if ($protected) {
            $this->protected[(string) $asset] = true;
        } else {
            unset($this->protected[(string) $asset]);
        }

        return $this;
    }

    public function forget(AssetRef $asset): self
    {
        $key = (string) $asset;
        unset($this->assets[$key], $this->protected[$key]);

        return $this;
    }

    #[Override]
    public function resolve(AssetRef $asset, ?string $variant = null): ?ResolvedAsset
    {
        $resolved = $this->assets[(string) $asset] ?? null;

        if ($resolved === null) {
            return null;
        }

        if ($variant === null || ! isset($resolved->variants[$variant])) {
            return $resolved;
        }

        return new ResolvedAsset(
            ref: $resolved->ref,
            url: $resolved->variants[$variant],
            mimeType: $resolved->mimeType,
            sizeBytes: $resolved->sizeBytes,
            widthPx: $resolved->widthPx,
            heightPx: $resolved->heightPx,
            altText: $resolved->altText,
            variants: $resolved->variants,
        );
    }

    /**
     * @param  list<AssetRef>  $assets
     * @return array<string, ResolvedAsset>
     */
    #[Override]
    public function resolveMany(array $assets, ?string $variant = null): array
    {
        $found = [];

        foreach ($assets as $asset) {
            $resolved = $this->resolve($asset, $variant);

            if ($resolved !== null) {
                $found[(string) $asset] = $resolved;
            }
        }

        return $found;
    }

    #[Override]
    public function signedUrl(AssetRef $asset, DateTimeImmutable $expiresAt, ?string $variant = null): ?string
    {
        $resolved = $this->resolve($asset, $variant);

        if ($resolved === null) {
            return null;
        }

        $separator = str_contains($resolved->url, '?') ? '&' : '?';

        return $resolved->url.$separator.'expires='.$expiresAt->getTimestamp();
    }
}

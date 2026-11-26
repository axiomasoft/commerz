<?php

declare(strict_types=1);

namespace Commerz\Ports\Assets\Internal;

use Commerz\Ports\Assets\Contracts\AssetResolverPort;
use Commerz\Ports\Assets\Data\AssetRef;
use Commerz\Ports\Assets\Data\ResolvedAsset;
use DateTimeImmutable;
use Override;
use Packman\Attributes\Internal;

/** No-op default when the host does not bind an `AssetResolverPort` adapter. */
#[Internal]
final readonly class NullAssetResolver implements AssetResolverPort
{
    #[Override]
    public function resolve(AssetRef $asset, ?string $variant = null): ?ResolvedAsset
    {
        return null;
    }

    #[Override]
    public function resolveMany(array $assets, ?string $variant = null): array
    {
        return [];
    }

    #[Override]
    public function signedUrl(AssetRef $asset, DateTimeImmutable $expiresAt, ?string $variant = null): ?string
    {
        return null;
    }
}

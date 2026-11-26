<?php

declare(strict_types=1);

namespace Commerz\Ports\Assets\Contracts;

use Commerz\Ports\Assets\Data\AssetRef;
use Commerz\Ports\Assets\Data\ResolvedAsset;
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Host-agnostic media and file resolution port. Commerce stores only opaque `AssetRef` pointers and asks the host to
 * resolve public or temporary signed URLs without coupling to any storage or media subsystem.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface AssetResolverPort
{
    public function resolve(AssetRef $asset, ?string $variant = null): ?ResolvedAsset;

    /**
     * Batch resolution without N+1 queries. Missing assets are omitted from the returned map.
     *
     * @param  list<AssetRef>  $assets
     * @return array<string, ResolvedAsset> keyed by `(string) $asset`
     */
    public function resolveMany(array $assets, ?string $variant = null): array;

    /** Generates a time-limited URL for a protected asset, or `null` when unavailable. */
    public function signedUrl(AssetRef $asset, DateTimeImmutable $expiresAt, ?string $variant = null): ?string;
}

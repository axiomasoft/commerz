<?php

declare(strict_types=1);

namespace Commerz\Catalog\Routes\Data;

use Commerz\Stores\Data\ChannelId;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class UrlRouteView
{
    public function __construct(
        public UrlRouteId $id,
        public ChannelId $channel,
        public string $locale,
        public string $slug,
        public string $entityType,
        public string $entityId,
        public bool $isCanonical,
        public SeoMeta $seoMeta,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}

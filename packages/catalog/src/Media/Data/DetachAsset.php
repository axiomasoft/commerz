<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Data;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
final readonly class DetachAsset
{
    public function __construct(
        public AssetAttachmentId $attachment,
    ) {}
}

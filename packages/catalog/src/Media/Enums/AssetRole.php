<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Enums;

use Packman\Attributes\Api;

#[Api(since: '0.1')]
enum AssetRole: string
{
    case Thumbnail = 'thumbnail';
    case Gallery = 'gallery';
    case Swatch = 'swatch';
    case Video = 'video';
    case Document = 'document';
}

<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Enums;

use Commerz\Attributes\Api;

/** `active ↔ deprecated → retired`; retired is final. */
#[Api(since: '0.1')]
enum AttributeStatus: string
{
    case Active = 'active';
    case Deprecated = 'deprecated';
    case Retired = 'retired';

    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Active => $next === self::Deprecated || $next === self::Retired,
            self::Deprecated => $next === self::Active || $next === self::Retired,
            self::Retired => false,
        };
    }
}

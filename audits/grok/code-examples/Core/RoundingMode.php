<?php

declare(strict_types=1);

namespace Commerz\Money\Enums;

use Brick\Math\RoundingMode as BrickRoundingMode;
use Commerz\Attributes\Api;

#[Api(since: '1.0')]
enum RoundingMode: string
{
    case HalfUp = 'half_up';     // налоги, конвертация — по умолчанию
    case HalfEven = 'half_even'; // банковское
    case Down = 'down';          // скидки: никогда не больше заявленной

    public function toBrick(): BrickRoundingMode
    {
        return match ($this) {
            self::HalfUp => BrickRoundingMode::HALF_UP,
            self::HalfEven => BrickRoundingMode::HALF_EVEN,
            self::Down => BrickRoundingMode::DOWN,
        };
    }
}

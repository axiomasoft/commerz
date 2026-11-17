<?php

declare(strict_types=1);

namespace Commerz\Money;

use Brick\Math\RoundingMode as BrickRoundingMode;
use Packman\Attributes\Api;

/** How a fractional minor unit is resolved. `Up`/`Down` act on magnitude; `Ceiling`/`Floor` on the signed value. */
#[Api(since: '0.1')]
enum RoundingMode: string
{
    case HalfUp = 'half_up';
    case HalfDown = 'half_down';
    case HalfEven = 'half_even';
    case Up = 'up';
    case Down = 'down';
    case Ceiling = 'ceiling';
    case Floor = 'floor';

    /** @internal */
    public function toBrick(): BrickRoundingMode
    {
        return match ($this) {
            self::HalfUp => BrickRoundingMode::HalfUp,
            self::HalfDown => BrickRoundingMode::HalfDown,
            self::HalfEven => BrickRoundingMode::HalfEven,
            self::Up => BrickRoundingMode::Up,
            self::Down => BrickRoundingMode::Down,
            self::Ceiling => BrickRoundingMode::Ceiling,
            self::Floor => BrickRoundingMode::Floor,
        };
    }
}

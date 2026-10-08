<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Enums;

use Commerz\Attributes\Api;

#[Api(since: '1.0')]
enum MeasureUnit: string
{
    case Watt = 'W';
    case Kilowatt = 'kW';
    case Millimetre = 'mm';
    case Centimetre = 'cm';
    case Metre = 'm';
    case Gram = 'g';
    case Kilogram = 'kg';

    public function dimension(): string
    {
        return match ($this) {
            self::Watt, self::Kilowatt => 'power',
            self::Millimetre, self::Centimetre, self::Metre => 'length',
            self::Gram, self::Kilogram => 'mass',
        };
    }

    /** Множитель к канонической единице измерения: W, mm, g. */
    public function toCanonicalFactor(): string
    {
        return match ($this) {
            self::Watt, self::Millimetre, self::Gram => '1',
            self::Centimetre => '10',
            self::Kilowatt, self::Metre, self::Kilogram => '1000',
        };
    }
}

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use DomainException;

#[Api(since: '1.0')]
final class InvalidAttributeValue extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Invalid attribute value: {$reason}.");
    }
}

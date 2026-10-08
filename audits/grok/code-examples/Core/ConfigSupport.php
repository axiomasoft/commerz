<?php

declare(strict_types=1);

namespace Commerz\Config\Enums;

use Commerz\Attributes\Api;

#[Api(since: '1.0')]
enum AvailabilitySource: string
{
    case Inventory = 'inventory'; // commerz-inventory считает остатки
    case Unlimited = 'unlimited'; // цифровые товары/услуги; явный выбор, не молчаливый дефолт
}

namespace Commerz\Config\Exceptions;

use Commerz\Attributes\Api;
use RuntimeException;

#[Api(since: '1.0')]
final class InvalidConfiguration extends RuntimeException
{
    public static function at(string $key, string $expectation): self
    {
        return new self("Invalid configuration [{$key}]: {$expectation}.");
    }
}

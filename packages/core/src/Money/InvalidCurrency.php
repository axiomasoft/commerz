<?php

declare(strict_types=1);

namespace Commerz\Money;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

#[Api(since: '0.1')]
final class InvalidCurrency extends InvalidCommand
{
    public function __construct(public readonly string $given)
    {
        parent::__construct('currency', "'{$given}' is not a known ISO 4217 code", 'money.invalid_currency');
    }
}

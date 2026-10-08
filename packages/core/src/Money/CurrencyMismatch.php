<?php

declare(strict_types=1);

namespace Commerz\Money;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

#[Api(since: '0.1')]
final class CurrencyMismatch extends InvalidCommand
{
    public function __construct(public readonly string $left, public readonly string $right)
    {
        parent::__construct('currency', "{$left} and {$right} cannot be combined", 'money.currency_mismatch');
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Attributes\Api;
use Commerz\Money\Currency;

#[Api(since: '0.1')]
final readonly class CreateStore
{
    public function __construct(
        public string $code,
        public string $name,
        public Currency $defaultCurrency,
        public string $orderNumberPrefix = '',
    ) {
        Codes::code($code);
        Codes::name($name);
        Codes::orderNumberPrefix($orderNumberPrefix);
    }
}

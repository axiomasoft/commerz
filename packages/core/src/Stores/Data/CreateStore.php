<?php

declare(strict_types=1);

namespace Commerz\Stores\Data;

use Commerz\Money\Currency;
use Packman\Attributes\Api;

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

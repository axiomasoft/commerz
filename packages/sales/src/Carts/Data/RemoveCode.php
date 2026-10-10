<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class RemoveCode
{
    public string $code;

    public function __construct(public CartId $cart, string $code)
    {
        $this->code = EnterCode::normalize($code);
    }
}

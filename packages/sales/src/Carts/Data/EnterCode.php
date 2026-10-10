<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** Presents a code (a coupon) with the cart. Whether it applies is up to the quote stages; entering it uses no limit. */
#[Api(since: '0.1')]
final readonly class EnterCode
{
    public string $code;

    public function __construct(public CartId $cart, string $code)
    {
        $this->code = self::normalize($code);
    }

    /** @internal shared with RemoveCode */
    public static function normalize(string $code): string
    {
        $code = trim($code);

        if ($code === '' || strlen($code) > 64) {
            throw new InvalidCommand('code', 'must be 1..64 bytes', 'sales.invalid_code');
        }

        return $code;
    }
}

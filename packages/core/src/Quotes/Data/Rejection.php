<?php

declare(strict_types=1);

namespace Commerz\Quotes\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Why a promotion or a presented code was not applied. */
#[Api(since: '0.1')]
final readonly class Rejection
{
    /**
     * @param  string  $source  stage or rule key that decided, e.g. `promotions.cart`
     * @param  string  $reason  stable snake_case code, e.g. `minimum_not_reached`
     * @param  string|null  $code  the presented code it concerns
     */
    public function __construct(
        public string $source,
        public string $reason,
        public string $message,
        public ?string $promotionId = null,
        public ?string $code = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $reason) !== 1) {
            throw new InvalidCommand('reason', "'{$reason}' must be a snake_case code", 'quotes.invalid_rejection');
        }
    }
}

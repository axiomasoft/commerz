<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** A rule as a command or a view carries it: the contribution key and the parameters (canonical in a view). */
#[Api(since: '0.1')]
final readonly class RuleSpec
{
    /** @param  array<string, mixed>  $params  see `RuleParams::fromInput` for what a value may be */
    public function __construct(public string $key, public array $params = [])
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $key) !== 1 || strlen($key) > 64) {
            throw new InvalidCommand('key', "'{$key}' must be a rule key such as 'cart.subtotal_at_least' or 'acme.vip_only'", 'promotions.invalid_rule');
        }
    }
}

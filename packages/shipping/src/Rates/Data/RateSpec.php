<?php

declare(strict_types=1);

namespace Commerz\Shipping\Rates\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** A rate as a command or a view carries it: the contribution key and the parameters (canonical in a view). */
#[Api(since: '0.1')]
final readonly class RateSpec
{
    /** @param  array<string, mixed>  $params  see `RateParams::fromInput` for what a value may be */
    public function __construct(public string $key, public array $params = [])
    {
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $key) !== 1 || strlen($key) > 64) {
            throw new InvalidCommand('key', "'{$key}' must be a rate key such as 'flat' or 'acme.zone_matrix'", 'shipping.invalid_rate');
        }
    }
}

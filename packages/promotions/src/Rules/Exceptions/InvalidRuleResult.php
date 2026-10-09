<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A condition, effect or activator answered with something the engine cannot trust. */
#[Api(since: '0.1')]
final class InvalidRuleResult extends CommerzException
{
    public function __construct(public readonly string $rule, public readonly string $reason)
    {
        parent::__construct("Rule '{$rule}' returned an invalid result: {$reason}.");
    }

    public function code(): string
    {
        return 'promotions.invalid_rule_result';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['rule' => $this->rule, 'reason' => $this->reason];
    }
}

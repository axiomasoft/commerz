<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** The answer of a condition: pass, with the lines that stay eligible for a line filter, or fail with a reason. */
#[Api(since: '0.1')]
final readonly class ConditionOutcome
{
    /**
     * @param  list<string>|null  $lines  the eligible subset (line filter) or null (qualifier)
     * @param  string|null  $reason  snake_case code of a failure, e.g. `minimum_not_reached`
     */
    private function __construct(
        public bool $passed,
        public ?array $lines,
        public ?string $reason,
        public ?string $message,
    ) {}

    /** @param  list<string>|null  $lines  a line filter's subset of `ConditionInput::$lines`; a qualifier passes null */
    public static function pass(?array $lines = null): self
    {
        return new self(true, $lines, null, null);
    }

    public static function fail(string $reason, string $message): self
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $reason) !== 1) {
            throw new InvalidCommand('reason', "'{$reason}' must be a snake_case code", 'promotions.invalid_condition_outcome');
        }

        return new self(false, null, $reason, $message);
    }
}

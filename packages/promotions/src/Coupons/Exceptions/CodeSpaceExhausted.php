<?php

declare(strict_types=1);

namespace Commerz\Promotions\Coupons\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** Generation kept meeting codes that already exist: the pattern is used up. Nothing was created. */
#[Api(since: '0.1')]
final class CodeSpaceExhausted extends CommerzException
{
    public function __construct(public readonly string $pattern, public readonly int $missing)
    {
        parent::__construct("Pattern '{$pattern}' has no room for {$missing} more new codes.");
    }

    public function code(): string
    {
        return 'promotions.code_space_exhausted';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['pattern' => $this->pattern, 'missing' => $this->missing];
    }
}

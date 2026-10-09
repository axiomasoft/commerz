<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The promotion is archived, which is final: it can no longer be changed, paused or resumed. */
#[Api(since: '0.1')]
final class PromotionArchived extends CommerzException
{
    public function __construct(public readonly string $promotion)
    {
        parent::__construct("Promotion '{$promotion}' is archived.");
    }

    public function code(): string
    {
        return 'promotions.promotion_archived';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['promotion' => $this->promotion];
    }
}

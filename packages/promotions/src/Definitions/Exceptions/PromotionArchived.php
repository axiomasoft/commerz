<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::PromotionArchived,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}

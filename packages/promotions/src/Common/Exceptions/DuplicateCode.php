<?php

declare(strict_types=1);

namespace Commerz\Promotions\Common\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** A promotion code or a coupon code is already taken in this installation. */
#[Api(since: '0.1')]
final class DuplicateCode extends CommerzException
{
    public function __construct(public readonly string $entity, public readonly string $duplicate)
    {
        parent::__construct("{$entity} code '{$duplicate}' is already taken.");
    }

    public function code(): string
    {
        return 'promotions.duplicate_code';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['entity' => $this->entity, 'code' => $this->duplicate];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::DuplicateCode,
            category: PackmanErrorCategory::Conflict,
            retryable: false,
            details: $this->context(),
        );
    }
}

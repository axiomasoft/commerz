<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Exceptions;

use Commerz\Catalog\Errors\Enums\CatalogErrorCode;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** A generation plans more combinations than `commerz-catalog.max_generated_variants` allows; nothing was written. */
#[Api(since: '0.1')]
final class TooManyVariants extends CommerzException
{
    public function __construct(public readonly int $requested, public readonly int $limit)
    {
        parent::__construct("{$requested} variants requested, at most {$limit} can be generated at once.");
    }

    public function code(): string
    {
        return 'catalog.too_many_variants';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Limit;
    }

    public function context(): array
    {
        return ['requested' => $this->requested, 'limit' => $this->limit];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CatalogErrorCode::TooManyVariants,
            category: PackmanErrorCategory::RateLimit,
            retryable: false,
            details: $this->context(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The parameters of a rule are wrong; `field` is the parameter, e.g. `min` or `amount`. */
#[Api(since: '0.1')]
final class InvalidRuleConfig extends CommerzException
{
    public function __construct(public readonly string $field, public readonly string $reason)
    {
        parent::__construct("Invalid rule parameter {$field}: {$reason}.");
    }

    public function code(): string
    {
        return 'promotions.invalid_rule_config';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['field' => $this->field, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::InvalidRuleConfig,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}

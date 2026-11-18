<?php

declare(strict_types=1);

namespace Commerz\Promotions\Rules\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Promotions\Errors\Enums\PromotionsErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

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
        return ErrorCategory::Unavailable;
    }

    public function context(): array
    {
        return ['rule' => $this->rule, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: PromotionsErrorCode::InvalidRuleResult,
            category: ErrorCategory::Unavailable,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

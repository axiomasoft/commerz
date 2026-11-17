<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Errors\Enums\CommerzErrorCode;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;
use Packman\Errors\Exceptions\CodedException;

/**
 * Root of all Commerz domain errors. Exposes both the Packman {@see ErrorDescriptor}
 * contract and legacy Commerz machine metadata (`code()`, `category()`, `context()`).
 */
#[Api(since: '0.1')]
abstract class CommerzException extends CodedException
{
    abstract public function code(): string;

    abstract public function category(): ErrorCategory;

    /** @return array<string, scalar|null> */
    abstract public function context(): array;

    public function errorDescriptor(): ErrorDescriptor
    {
        $category = match ($this->category()) {
            ErrorCategory::Validation, ErrorCategory::Configuration => PackmanErrorCategory::Validation,
            ErrorCategory::NotFound => PackmanErrorCategory::NotFound,
            ErrorCategory::Conflict => PackmanErrorCategory::Conflict,
            ErrorCategory::Limit => PackmanErrorCategory::RateLimit,
            ErrorCategory::Unsupported => PackmanErrorCategory::Unsupported,
            ErrorCategory::Integration => PackmanErrorCategory::Unavailable,
        };

        $code = match ($this->category()) {
            ErrorCategory::NotFound => CommerzErrorCode::NotFound,
            ErrorCategory::Conflict => CommerzErrorCode::IdempotencyConflict,
            ErrorCategory::Limit => CommerzErrorCode::UsageLimitReached,
            ErrorCategory::Unsupported => CommerzErrorCode::UnsupportedContribution,
            ErrorCategory::Configuration => CommerzErrorCode::InvalidConfiguration,
            default => CommerzErrorCode::InvalidCommand,
        };

        return new ErrorDescriptor(
            code: $code,
            category: $category,
            retryable: false,
            details: $this->context() + ['legacy_code' => $this->code()],
        );
    }
}

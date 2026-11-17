<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/**
 * Legacy configuration error retained during Slice A until all feature subpackages migrate to Packman\Config.
 *
 * @deprecated Use Packman\Config\Exceptions\InvalidConfiguration instead.
 */
#[Api(since: '0.1')]
final class InvalidConfiguration extends CommerzException
{
    public function __construct(public readonly string $path, public readonly string $reason)
    {
        parent::__construct("Invalid configuration {$path}: {$reason}.");
    }

    public function code(): string
    {
        return 'config.invalid';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Configuration;
    }

    public function context(): array
    {
        return ['path' => $this->path, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::InvalidConfiguration,
            category: PackmanErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}

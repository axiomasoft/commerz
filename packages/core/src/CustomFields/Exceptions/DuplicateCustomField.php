<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Raised when a custom field with the same `(targetAggregate, namespace, key)` is registered twice.
 */
#[Api(since: '0.1')]
final class DuplicateCustomField extends CommerzException
{
    public function __construct(
        public readonly string $targetAggregate,
        public readonly string $namespace,
        public readonly string $key,
    ) {
        parent::__construct("Custom field '{$namespace}.{$key}' is already registered for aggregate '{$targetAggregate}'.");
    }

    #[Override]
    public function code(): string
    {
        return 'custom_fields.duplicate_field';
    }

    #[Override]
    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    /**
     * @return array<string, scalar|null>
     */
    #[Override]
    public function context(): array
    {
        return [
            'entity' => 'CustomField',
            'code' => "{$this->targetAggregate}:{$this->namespace}.{$this->key}",
        ];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::DuplicateCode,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

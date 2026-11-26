<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Exceptions;

use Commerz\Errors\NotFound;
use Packman\Attributes\Api;

/**
 * Raised when a custom field definition is not registered for the given target aggregate, namespace, and key.
 */
#[Api(since: '0.1')]
final class CustomFieldNotFound extends NotFound
{
    public function __construct(
        public readonly string $targetAggregate,
        public readonly string $namespace,
        public readonly string $key,
    ) {
        parent::__construct(
            'CustomField',
            "{$targetAggregate}:{$namespace}.{$key}",
            'custom_fields.not_found',
        );
    }
}

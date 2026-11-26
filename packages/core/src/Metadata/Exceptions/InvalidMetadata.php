<?php

declare(strict_types=1);

namespace Commerz\Metadata\Exceptions;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Raised when a metadata map violates key, value, depth, or size constraints.
 */
#[Api(since: '0.1')]
final class InvalidMetadata extends InvalidCommand
{
    public function __construct(string $field, string $reason)
    {
        parent::__construct($field, $reason, 'metadata.invalid_metadata');
    }
}

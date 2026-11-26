<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Exceptions;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Raised when one or more required custom fields for an aggregate are absent or removed.
 */
#[Api(since: '0.1')]
final class MissingRequiredCustomFields extends InvalidCommand
{
    /**
     * @param  non-empty-list<string>  $missingKeys
     */
    public function __construct(
        public readonly string $targetAggregate,
        public readonly array $missingKeys,
    ) {
        parent::__construct(
            'customFields',
            "missing required custom fields for '{$targetAggregate}': ".implode(', ', $missingKeys),
            'custom_fields.missing_required',
        );
    }
}

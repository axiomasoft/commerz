<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Exceptions;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Raised when a query attempts to filter on a custom field that was not registered with `isFilterable: true`.
 */
#[Api(since: '0.1')]
final class CustomFieldNotFilterable extends InvalidCommand
{
    public function __construct(string $targetAggregate, string $qualifiedKey)
    {
        parent::__construct(
            $qualifiedKey,
            "custom field '{$qualifiedKey}' on '{$targetAggregate}' is not filterable",
            'custom_fields.not_filterable',
        );
    }
}

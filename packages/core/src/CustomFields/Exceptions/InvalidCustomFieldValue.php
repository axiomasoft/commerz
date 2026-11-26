<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Exceptions;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Raised when a custom field value fails type normalization or configured validation rules.
 */
#[Api(since: '0.1')]
final class InvalidCustomFieldValue extends InvalidCommand
{
    public function __construct(string $qualifiedKey, string $reason)
    {
        parent::__construct($qualifiedKey, $reason, 'custom_fields.invalid_value');
    }
}

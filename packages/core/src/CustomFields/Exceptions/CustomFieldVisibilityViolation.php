<?php

declare(strict_types=1);

namespace Commerz\CustomFields\Exceptions;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/**
 * Raised when a private custom field is written to public metadata, or accessed/filtered from a storefront audience.
 */
#[Api(since: '0.1')]
final class CustomFieldVisibilityViolation extends InvalidCommand
{
    public function __construct(string $qualifiedKey, string $reason)
    {
        parent::__construct($qualifiedKey, $reason, 'custom_fields.visibility_violation');
    }
}

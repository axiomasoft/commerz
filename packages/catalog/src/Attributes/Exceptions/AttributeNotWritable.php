<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** The attribute is deprecated or retired: existing values stay, new ones are refused. */
#[Api(since: '0.1')]
final class AttributeNotWritable extends CommerzException
{
    public function __construct(public readonly string $attribute, public readonly string $status)
    {
        parent::__construct("Attribute '{$attribute}' is {$status} and takes no new values.");
    }

    public function code(): string
    {
        return 'catalog.attribute_not_writable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'status' => $this->status];
    }
}

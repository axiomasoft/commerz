<?php

declare(strict_types=1);

namespace Commerz\Pricing\Common\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A price list, tax class or tax zone code is already taken in this installation. */
#[Api(since: '0.1')]
final class DuplicateCode extends CommerzException
{
    public function __construct(public readonly string $entity, public readonly string $duplicate)
    {
        parent::__construct("{$entity} code '{$duplicate}' is already taken.");
    }

    public function code(): string
    {
        return 'pricing.duplicate_code';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['entity' => $this->entity, 'code' => $this->duplicate];
    }
}

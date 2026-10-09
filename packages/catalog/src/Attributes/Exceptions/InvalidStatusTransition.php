<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A status change the entity's lifecycle does not allow. */
#[Api(since: '0.1')]
final class InvalidStatusTransition extends CommerzException
{
    public function __construct(public readonly string $entity, public readonly string $from, public readonly string $to)
    {
        parent::__construct("{$entity} cannot go from '{$from}' to '{$to}'.");
    }

    public function code(): string
    {
        return 'catalog.invalid_status_transition';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['entity' => $this->entity, 'from' => $this->from, 'to' => $this->to];
    }
}

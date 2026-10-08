<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Attributes\Api;

/** A referenced entity does not exist. Domain packages subclass it with their own code. */
#[Api(since: '0.1')]
class NotFound extends CommerzException
{
    public function __construct(
        public readonly string $entity,
        public readonly string $id,
        private readonly string $errorCode = 'common.not_found',
    ) {
        parent::__construct("{$entity} '{$id}' was not found.");
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['entity' => $this->entity, 'id' => $this->id];
    }
}

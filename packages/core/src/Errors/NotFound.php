<?php

declare(strict_types=1);

namespace Commerz\Errors;

use Commerz\Errors\Enums\CommerzErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

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

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $details = $this->context();

        if ($this->errorCode !== 'common.not_found') {
            $details['legacy_code'] = $this->errorCode;
        }

        return new ErrorDescriptor(
            code: CommerzErrorCode::NotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $details,
        );
    }
}

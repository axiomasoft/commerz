<?php

declare(strict_types=1);

namespace Commerz\Stores\Exceptions;

use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

#[Api(since: '0.1')]
final class StoreNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Store', $id, 'stores.store_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::StoreNotFound,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}

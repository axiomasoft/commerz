<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class TransferNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Transfer', $id, 'inventory.transfer_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::TransferNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

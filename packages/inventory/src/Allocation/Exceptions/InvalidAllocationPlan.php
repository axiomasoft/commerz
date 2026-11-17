<?php

declare(strict_types=1);

namespace Commerz\Inventory\Allocation\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Inventory\Errors\Enums\InventoryErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** An allocation strategy proposed a plan the sources cannot honour; the whole command is rolled back. */
#[Api(since: '0.1')]
final class InvalidAllocationPlan extends CommerzException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Invalid allocation plan: {$reason}.");
    }

    public function code(): string
    {
        return 'inventory.invalid_allocation_plan';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Integration;
    }

    public function context(): array
    {
        return ['reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: InventoryErrorCode::InvalidAllocationPlan,
            category: PackmanErrorCategory::Unavailable,
            retryable: false,
            details: $this->context(),
        );
    }
}

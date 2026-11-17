<?php

declare(strict_types=1);

namespace Commerz\Ports\Sellables\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\ErrorCategory;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory as PackmanErrorCategory;

/** The provider of the ref type does not know the ref (deleted, never existed, or hidden from the channel). */
#[Api(since: '0.1')]
final class SellableUnavailable extends CommerzException
{
    public function __construct(public readonly string $sellable, public readonly string $reason = 'unknown')
    {
        parent::__construct("Sellable '{$sellable}' is unavailable: {$reason}.");
    }

    public function code(): string
    {
        return 'sellables.unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }

    public function context(): array
    {
        return ['sellable' => $this->sellable, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::SellableUnavailable,
            category: PackmanErrorCategory::NotFound,
            retryable: false,
            details: $this->context(),
        );
    }
}

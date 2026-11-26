<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class ClaimNotOpen extends CommerzException
{
    public function __construct(public readonly string $claim, public readonly string $status)
    {
        parent::__construct("Claim '{$claim}' is not open: it is {$status}.");
    }

    public function code(): string
    {
        return 'sales.claim_not_open';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['claim' => $this->claim, 'status' => $this->status];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::ClaimNotOpen,
            category: ErrorCategory::Conflict,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

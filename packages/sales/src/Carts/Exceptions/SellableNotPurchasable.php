<?php

declare(strict_types=1);

namespace Commerz\Sales\Carts\Exceptions;

use Commerz\Errors\CommerzException;
use Commerz\Identity\SellableRef;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The sellable exists but its owner does not sell it now (CTX-01); `$reason` is the owner's own. */
#[Api(since: '0.1')]
final class SellableNotPurchasable extends CommerzException
{
    public function __construct(public readonly SellableRef $sellable, public readonly ?string $reason = null)
    {
        parent::__construct("Sellable '{$sellable}' cannot be purchased".($reason === null ? '.' : ": {$reason}."));
    }

    public function code(): string
    {
        return 'sales.sellable_not_purchasable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Validation;
    }

    public function context(): array
    {
        return ['sellable' => (string) $this->sellable, 'reason' => $this->reason];
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::SellableNotPurchasable,
            category: ErrorCategory::Validation,
            retryable: false,
            details: $this->context(),
        );
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Sales\Exchanges\Exceptions;

use Commerz\Errors\NotFound;
use Commerz\Sales\Errors\Enums\SalesErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

#[Api(since: '0.1')]
final class ExchangeNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Exchange', $id, 'sales.exchange_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: SalesErrorCode::ExchangeNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

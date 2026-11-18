<?php

declare(strict_types=1);

namespace Commerz\Ports\Prices\Exceptions;

use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** No price applies to the sellable in this context and currency. */
#[Api(since: '0.1')]
final class PriceNotFound extends NotFound
{
    public function __construct(string $sellable, string $currency)
    {
        parent::__construct('Price', "{$sellable} in {$currency}", 'prices.price_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::PriceNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

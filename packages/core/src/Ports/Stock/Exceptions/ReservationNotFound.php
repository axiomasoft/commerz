<?php

declare(strict_types=1);

namespace Commerz\Ports\Stock\Exceptions;

use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\NotFound;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Enums\ErrorCategory;

/** The demand holds no (or not enough) reserved stock of the sellable. */
#[Api(since: '0.1')]
final class ReservationNotFound extends NotFound
{
    public function __construct(string $demand, string $sellable)
    {
        parent::__construct('Reservation', "{$demand}/{$sellable}", 'stock.reservation_not_found');
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: CommerzErrorCode::ReservationNotFound,
            category: ErrorCategory::NotFound,
            retryable: false,
            details: $this->descriptorDetails(),
        );
    }
}

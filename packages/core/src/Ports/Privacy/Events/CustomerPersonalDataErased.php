<?php

declare(strict_types=1);

namespace Commerz\Ports\Privacy\Events;

use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;

/**
 * Dispatched after a customer's personal data has been erased in sales so peer packages (such as shipping bookings)
 * can anonymize their own records for the same customer and related references.
 */
#[Api(since: '0.1')]
final readonly class CustomerPersonalDataErased
{
    /** @param  list<string>  $bookingReferences */
    public function __construct(
        public CustomerRef $customer,
        public array $bookingReferences = [],
    ) {}
}

<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class PaymentNotFound extends NotFound
{
    /** @param  string  $id  the payment id, reference or the provider's id it was looked up by */
    public function __construct(string $id)
    {
        parent::__construct('Payment', $id, 'sales.payment_not_found');
    }
}

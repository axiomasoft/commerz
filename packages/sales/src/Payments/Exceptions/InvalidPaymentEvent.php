<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;

/** A provider event is malformed or does not fit its payment (another currency, more than the payment); it is not recorded. */
#[Api(since: '0.1')]
final class InvalidPaymentEvent extends InvalidCommand
{
    public function __construct(string $field, string $reason)
    {
        parent::__construct($field, $reason, 'sales.invalid_payment_event');
    }
}

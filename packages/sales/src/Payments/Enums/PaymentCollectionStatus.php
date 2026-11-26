<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Enums;

use Packman\Attributes\Api;

/** Derived split-tender aggregate state of a payment collection across all its payment sessions. */
#[Api(since: '0.1')]
enum PaymentCollectionStatus: string
{
    case NotPaid = 'not_paid';
    case PartiallyAuthorized = 'partially_authorized';
    case Authorized = 'authorized';
    case PartiallyCaptured = 'partially_captured';
    case Captured = 'captured';
    case Cancelled = 'cancelled';
}

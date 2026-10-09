<?php

declare(strict_types=1);

namespace Commerz\Shipping\Options\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/**
 * The selected delivery method cannot deliver this quote; `$verdict` is the explain verdict that says why. The
 * delivery stage fails the quote with it (`quotes.failed`, reason `method_unavailable`) instead of quoting no delivery.
 */
#[Api(since: '0.1')]
final class DeliveryUnavailable extends CommerzException
{
    public function __construct(
        public readonly string $method,
        public readonly string $verdict,
        public readonly string $reason,
    ) {
        parent::__construct("Shipping method '{$method}' is unavailable ({$verdict}): {$reason}");
    }

    public function code(): string
    {
        return 'shipping.delivery_unavailable';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['method' => $this->method, 'verdict' => $this->verdict];
    }
}

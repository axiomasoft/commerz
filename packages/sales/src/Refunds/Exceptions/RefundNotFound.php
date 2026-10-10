<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

#[Api(since: '0.1')]
final class RefundNotFound extends NotFound
{
    /** @param  string  $id  the refund id, reference or the provider's id it was looked up by */
    public function __construct(string $id)
    {
        parent::__construct('Refund', $id, 'sales.refund_not_found');
    }
}

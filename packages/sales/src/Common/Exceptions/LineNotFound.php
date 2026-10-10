<?php

declare(strict_types=1);

namespace Commerz\Sales\Common\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\NotFound;

/** The order has no line with this id. */
#[Api(since: '0.1')]
final class LineNotFound extends NotFound
{
    public function __construct(string $id)
    {
        parent::__construct('Order line', $id, 'sales.line_not_found');
    }
}

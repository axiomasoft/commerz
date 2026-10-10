<?php

declare(strict_types=1);

namespace Commerz\Sales\Returns\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;

/** A received return cannot be cancelled, nor a cancelled one received. */
#[Api(since: '0.1')]
final class ReturnNotOpen extends CommerzException
{
    public function __construct(public readonly string $return, public readonly string $status)
    {
        parent::__construct("Return '{$return}' is not open: it is {$status}.");
    }

    public function code(): string
    {
        return 'sales.return_not_open';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['return' => $this->return, 'status' => $this->status];
    }
}

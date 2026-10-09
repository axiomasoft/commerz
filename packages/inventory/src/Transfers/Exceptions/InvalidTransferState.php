<?php

declare(strict_types=1);

namespace Commerz\Inventory\Transfers\Exceptions;

use Commerz\Attributes\Api;
use Commerz\Errors\CommerzException;
use Commerz\Errors\ErrorCategory;
use Commerz\Inventory\Transfers\Enums\TransferState;

/** The command does not apply to the transfer's state: a transfer is dispatched once, received only in transit. */
#[Api(since: '0.1')]
final class InvalidTransferState extends CommerzException
{
    public function __construct(public readonly string $transfer, public readonly TransferState $state, public readonly string $action)
    {
        parent::__construct("Transfer '{$transfer}' is {$state->value}: it cannot be {$action}.");
    }

    public function code(): string
    {
        return 'inventory.invalid_transfer_state';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['transfer' => $this->transfer, 'state' => $this->state->value, 'action' => $this->action];
    }
}

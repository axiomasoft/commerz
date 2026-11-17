<?php

declare(strict_types=1);

namespace Commerz\Operations\Contracts;

use Packman\Attributes\Api;

/**
 * Dispatches a domain (Laravel) event once the surrounding transaction on the Commerz connection
 * (`commerz.connection`) commits — ours or the host's. Outside a transaction the event is dispatched at once;
 * on rollback it is dropped.
 */
#[Api(since: '0.1')]
interface CommittedEvents
{
    public function dispatch(object $event): void;
}

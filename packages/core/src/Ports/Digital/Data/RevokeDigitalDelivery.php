<?php

declare(strict_types=1);

namespace Commerz\Ports\Digital\Data;

use Commerz\Errors\InvalidCommand;
use Packman\Attributes\Api;

/** Command to revoke a previously provisioned digital delivery entitlement (e.g. on refund or cancellation). */
#[Api(since: '0.1')]
final readonly class RevokeDigitalDelivery
{
    public function __construct(
        public string $grantId,
        public ?string $reason = null,
    ) {
        if (trim($grantId) === '' || strlen($grantId) > 191 || str_contains($grantId, "\0")) {
            throw new InvalidCommand('grantId', 'must be 1..191 bytes without NUL', 'digital.invalid_revoke');
        }

        if ($reason !== null && (strlen($reason) > 500 || str_contains($reason, "\0"))) {
            throw new InvalidCommand('reason', 'must fit 500 bytes without NUL', 'digital.invalid_revoke');
        }
    }
}

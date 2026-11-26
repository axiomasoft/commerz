<?php

declare(strict_types=1);

namespace Commerz\Ports\Digital\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\SellableRef;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/** Provisioned digital entitlement or download token returned by a host `DigitalDeliveryPort`. */
#[Api(since: '0.1')]
final readonly class DigitalDeliveryGrant
{
    public ?DateTimeImmutable $expiresAt;

    public function __construct(
        public string $grantId,
        public string $reference,
        public SellableRef $sellable,
        public ?string $downloadUrl = null,
        #[\SensitiveParameter]
        public ?string $licenseKey = null,
        ?DateTimeImmutable $expiresAt = null,
        public ?int $remainingDownloads = null,
        public bool $revoked = false,
    ) {
        if (trim($grantId) === '' || strlen($grantId) > 191 || str_contains($grantId, "\0")) {
            throw new InvalidCommand('grantId', 'must be 1..191 bytes without NUL', 'digital.invalid_grant');
        }

        if (trim($reference) === '' || strlen($reference) > 191 || str_contains($reference, "\0")) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes without NUL', 'digital.invalid_grant');
        }

        if ($downloadUrl !== null && (trim($downloadUrl) === '' || strlen($downloadUrl) > 2048 || str_contains($downloadUrl, "\0"))) {
            throw new InvalidCommand('downloadUrl', 'must be 1..2048 bytes without NUL when set', 'digital.invalid_grant');
        }

        if ($licenseKey !== null && ($licenseKey === '' || str_contains($licenseKey, "\0"))) {
            throw new InvalidCommand('licenseKey', 'must be non-empty without NUL when set', 'digital.invalid_grant');
        }

        if ($remainingDownloads !== null && $remainingDownloads < 0) {
            throw new InvalidCommand('remainingDownloads', 'must not be negative', 'digital.invalid_grant');
        }

        $this->expiresAt = $expiresAt?->setTimezone(new DateTimeZone('UTC'));
    }
}

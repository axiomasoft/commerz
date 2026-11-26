<?php

declare(strict_types=1);

namespace Commerz\Ports\Digital\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\SellableRef;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Api;

/** Command to provision digital access, download links, or license entitlements for a purchased sellable. */
#[Api(since: '0.1')]
final readonly class GrantDigitalDelivery
{
    public ?DateTimeImmutable $expiresAt;

    /** @var array<string, scalar> */
    public array $metadata;

    /**
     * @param  string  $reference  idempotency reference (e.g. `fulfillment:019...`)
     * @param  array<string, scalar>  $metadata
     */
    public function __construct(
        public string $reference,
        public SellableRef $sellable,
        public string $orderRef,
        public int $quantity = 1,
        public ?CustomerRef $customer = null,
        public ?string $recipientEmail = null,
        ?DateTimeImmutable $expiresAt = null,
        public ?int $maxDownloads = null,
        array $metadata = [],
    ) {
        if (trim($reference) === '' || strlen($reference) > 191 || str_contains($reference, "\0")) {
            throw new InvalidCommand('reference', 'must be 1..191 bytes without NUL', 'digital.invalid_grant');
        }

        if (trim($orderRef) === '' || strlen($orderRef) > 191 || str_contains($orderRef, "\0")) {
            throw new InvalidCommand('orderRef', 'must be 1..191 bytes without NUL', 'digital.invalid_grant');
        }

        if ($quantity < 1) {
            throw new InvalidCommand('quantity', 'must be at least 1', 'digital.invalid_grant');
        }

        if ($recipientEmail !== null && (trim($recipientEmail) === '' || strlen($recipientEmail) > 191 || str_contains($recipientEmail, "\0"))) {
            throw new InvalidCommand('recipientEmail', 'must be 1..191 bytes without NUL when set', 'digital.invalid_grant');
        }

        if ($maxDownloads !== null && $maxDownloads < 1) {
            throw new InvalidCommand('maxDownloads', 'must be at least 1 when set', 'digital.invalid_grant');
        }

        $cleanMeta = [];

        foreach ($metadata as $key => $value) {
            if ($key === '' || str_contains($key, "\0") || (is_string($value) && str_contains($value, "\0"))) {
                throw new InvalidCommand('metadata', 'must map non-empty string keys to scalars without NUL', 'digital.invalid_grant');
            }

            if (is_float($value) && ! is_finite($value)) {
                throw new InvalidCommand('metadata', 'must not contain non-finite numbers', 'digital.invalid_grant');
            }

            $cleanMeta[$key] = $value;
        }

        $this->expiresAt = $expiresAt?->setTimezone(new DateTimeZone('UTC'));
        $this->metadata = $cleanMeta;
    }
}

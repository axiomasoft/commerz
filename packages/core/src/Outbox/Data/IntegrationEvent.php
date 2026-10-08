<?php

declare(strict_types=1);

namespace Commerz\Outbox\Data;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Ref;
use Commerz\Identity\Uuid7;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A fact published to other systems through the outbox. `type` is a dotted stable name (`sales.order_placed`),
 * `version` the payload schema version, `aggregateRef` the `type:id` the fact is about. Consumers deduplicate by
 * `eventId`: delivery is at least once.
 */
#[Api(since: '0.1')]
final readonly class IntegrationEvent
{
    public DateTimeImmutable $occurredAt;

    /** @param  array<string, mixed>  $payload  JSON-encodable */
    public function __construct(
        public string $eventId,
        public string $type,
        public int $version,
        public string $aggregateRef,
        public array $payload,
        DateTimeImmutable $occurredAt,
    ) {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $eventId) !== 1) {
            throw new InvalidCommand('eventId', 'must be a lower-case UUID', 'outbox.invalid_event');
        }

        if (strlen($type) > 191 || preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/', $type) !== 1) {
            throw new InvalidCommand('type', "'{$type}' must be a dotted lower-case name like sales.order_placed", 'outbox.invalid_event');
        }

        if ($version < 1 || $version > 32767) {
            throw new InvalidCommand('version', 'must be from 1 to 32767', 'outbox.invalid_event');
        }

        if (strlen($aggregateRef) > 191 || preg_match('/\A[a-z0-9_.\-]{1,64}:[^\p{Cc}]{1,120}\z/u', $aggregateRef) !== 1) {
            throw new InvalidCommand('aggregateRef', 'must look like type:id and fit 191 bytes', 'outbox.invalid_event');
        }

        $this->occurredAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A new event with a fresh UUIDv7 id, occurring now unless `$occurredAt` is given.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function of(string $type, Ref|string $aggregateRef, array $payload, int $version = 1, ?DateTimeImmutable $occurredAt = null): self
    {
        return new self(
            Uuid7::generate(),
            $type,
            $version,
            (string) $aggregateRef,
            $payload,
            $occurredAt ?? new DateTimeImmutable,
        );
    }
}

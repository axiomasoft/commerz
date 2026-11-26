<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Errors\NotFound;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Commerz\Webhooks\Contracts\InboundWebhookGateway;
use Commerz\Webhooks\Contracts\InboundWebhookHandler;
use Commerz\Webhooks\Data\InboundWebhookEventId;
use Commerz\Webhooks\Data\InboundWebhookPayload;
use Commerz\Webhooks\Data\InboundWebhookResult;
use Commerz\Webhooks\Data\InboundWebhookSource;
use Commerz\Webhooks\Data\ReceiveInboundWebhook;
use Commerz\Webhooks\Data\WebhookSignature;
use Commerz\Webhooks\Enums\InboundSignatureMode;
use Commerz\Webhooks\Enums\InboundWebhookOutcome;
use Commerz\Webhooks\Events\InboundWebhookReceived;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use Override;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final class DatabaseInboundWebhookGateway implements InboundWebhookGateway
{
    /** @var array<string, InboundWebhookSource> */
    private array $sources = [];

    /** @var array<string, InboundWebhookHandler> */
    private array $handlers = [];

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly CommerzOptions $options,
        private readonly TableNames $tables,
        private readonly Transactions $transactions,
        private readonly CommittedEvents $events,
        private readonly Clock $clock,
    ) {}

    #[Override]
    public function registerSource(InboundWebhookSource $source, ?InboundWebhookHandler $handler = null): void
    {
        $this->sources[$source->source] = $source;

        if ($handler !== null) {
            $this->handlers[$source->source] = $handler;
        }
    }

    #[Override]
    public function receive(
        ReceiveInboundWebhook $webhook,
        ?InboundWebhookSource $source = null,
        ?InboundWebhookHandler $handler = null,
    ): InboundWebhookResult {
        $effectiveSource = $source ?? ($this->sources[$webhook->source] ?? null);

        if ($effectiveSource === null) {
            throw new InvalidCommand('source', "inbound webhook source '{$webhook->source}' is not registered", 'webhooks.unknown_source');
        }

        if ($effectiveSource->source !== $webhook->source) {
            throw new InvalidCommand('source', 'supplied InboundWebhookSource does not match ReceiveInboundWebhook::$source', 'webhooks.source_mismatch');
        }

        $nowInstant = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        if ($effectiveSource->signatureMode === InboundSignatureMode::Timestamped) {
            WebhookSignature::assertValid(
                header: $webhook->signature,
                rawBody: $webhook->rawBody,
                secrets: $effectiveSource->secrets,
                now: $nowInstant,
                toleranceSeconds: $effectiveSource->toleranceSeconds,
                source: $webhook->source,
            );
        } else {
            WebhookSignature::assertPayloadValid(
                header: $webhook->signature,
                rawBody: $webhook->rawBody,
                secrets: $effectiveSource->secrets,
                source: $webhook->source,
            );
        }

        $decodedPayload = self::decodeJsonObject($webhook->rawBody);
        $payloadHash = hash('sha256', $webhook->rawBody);
        $effectiveHandler = $handler ?? ($this->handlers[$webhook->source] ?? null);

        return $this->transactions->run(function () use ($webhook, $decodedPayload, $payloadHash, $effectiveHandler, $nowInstant): InboundWebhookResult {
            $db = $this->db();
            $table = $this->tables->table('webhook_inbound_events');
            $id = Uuid7::generate();
            $nowStr = PgTimestamp::format($nowInstant);
            $encodedPayload = json_encode((object) $decodedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $encodedHeaders = json_encode((object) $webhook->headers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $claimed = false;

            try {
                $db->transaction(static function () use ($db, $table, $id, $webhook, $payloadHash, $encodedPayload, $encodedHeaders, $nowStr): void {
                    $db->table($table)->insert([
                        'id' => $id,
                        'source' => $webhook->source,
                        'external_event_id' => $webhook->externalEventId,
                        'event_type' => $webhook->eventType,
                        'payload_hash' => $payloadHash,
                        'payload' => $encodedPayload,
                        'headers' => $encodedHeaders,
                        'status' => InboundWebhookOutcome::Processed->value,
                        'received_at' => $nowStr,
                        'processed_at' => null,
                    ]);
                });
                $claimed = true;
            } catch (UniqueConstraintViolationException) {
                $claimed = false;
            }

            if (! $claimed) {
                $existing = $db->table($table)
                    ->where('source', $webhook->source)
                    ->where('external_event_id', $webhook->externalEventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    throw new RuntimeException("Inbound webhook '{$webhook->source}:{$webhook->externalEventId}' conflicted on unique key but could not be read.");
                }

                if (Rows::string($existing, 'payload_hash') !== $payloadHash) {
                    throw new IdempotencyConflict('webhooks.inbound_receive', "{$webhook->source}:{$webhook->externalEventId}");
                }

                return new InboundWebhookResult(
                    id: new InboundWebhookEventId(Rows::string($existing, 'id')),
                    source: Rows::string($existing, 'source'),
                    externalEventId: Rows::string($existing, 'external_event_id'),
                    eventType: Rows::string($existing, 'event_type'),
                    duplicate: true,
                    outcome: InboundWebhookOutcome::Duplicate,
                );
            }

            $eventId = new InboundWebhookEventId($id);
            $inboundPayload = new InboundWebhookPayload(
                id: $eventId,
                source: $webhook->source,
                externalEventId: $webhook->externalEventId,
                eventType: $webhook->eventType,
                payload: $decodedPayload,
                headers: $webhook->headers,
                receivedAt: $nowInstant,
            );

            if ($effectiveHandler !== null) {
                $effectiveHandler->handle($inboundPayload);
            }

            $processedAt = PgTimestamp::format($this->clock->now()->setTimezone(new DateTimeZone('UTC')));
            $db->table($table)->where('id', $id)->update(['processed_at' => $processedAt]);

            $this->events->dispatch(new InboundWebhookReceived($inboundPayload));

            return new InboundWebhookResult(
                id: $eventId,
                source: $webhook->source,
                externalEventId: $webhook->externalEventId,
                eventType: $webhook->eventType,
                duplicate: false,
                outcome: InboundWebhookOutcome::Processed,
            );
        });
    }

    #[Override]
    public function find(InboundWebhookEventId $id): InboundWebhookPayload
    {
        $row = $this->db()->table($this->tables->table('webhook_inbound_events'))
            ->where('id', $id->value)
            ->first();

        if ($row === null) {
            throw new NotFound('InboundWebhookEvent', $id->value, 'webhooks.inbound_not_found');
        }

        return WebhookRows::inboundPayload($row);
    }

    /** @return array<string, mixed> */
    private static function decodeJsonObject(string $rawBody): array
    {
        try {
            $object = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
            $assoc = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidCommand('rawBody', 'must be valid JSON', 'webhooks.invalid_json');
        }

        if (! is_object($object) || ! is_array($assoc)) {
            throw new InvalidCommand('rawBody', 'must decode to a JSON object', 'webhooks.invalid_json');
        }

        return WebhookRows::mixedMap($assoc);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->options->connection);
    }
}

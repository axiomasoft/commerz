<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Commerz\Webhooks\Contracts\WebhookSubscriptions;
use Commerz\Webhooks\Contracts\WebhookTransport;
use Commerz\Webhooks\Data\CreateWebhookSubscription;
use Commerz\Webhooks\Data\ListWebhookDeliveries;
use Commerz\Webhooks\Data\ListWebhookSubscriptions;
use Commerz\Webhooks\Data\OutboundWebhookRequest;
use Commerz\Webhooks\Data\ReplayWebhookDelivery;
use Commerz\Webhooks\Data\UpdateWebhookSubscription;
use Commerz\Webhooks\Data\WebhookDeliveryId;
use Commerz\Webhooks\Data\WebhookDeliveryView;
use Commerz\Webhooks\Data\WebhookDispatchReport;
use Commerz\Webhooks\Data\WebhookRetryPolicy;
use Commerz\Webhooks\Data\WebhookSignature;
use Commerz\Webhooks\Data\WebhookSubscriptionId;
use Commerz\Webhooks\Data\WebhookSubscriptionView;
use Commerz\Webhooks\Enums\WebhookDeliveryStatus;
use Commerz\Webhooks\Enums\WebhookSubscriptionStatus;
use Commerz\Webhooks\Events\WebhookDelivered;
use Commerz\Webhooks\Events\WebhookSubscriptionDisabled;
use Commerz\Webhooks\Exceptions\DuplicateWebhookSubscription;
use Commerz\Webhooks\Exceptions\WebhookDeliveryNotFound;
use Commerz\Webhooks\Exceptions\WebhookSubscriptionNotFound;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class DatabaseWebhookSubscriptions implements WebhookSubscriptions
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private WebhookTransport $transport,
        private Clock $clock,
        private WebhookRetryPolicy $retryPolicy = new WebhookRetryPolicy,
    ) {}

    #[Override]
    public function create(CreateWebhookSubscription $command): WebhookSubscriptionView
    {
        return $this->transactions->run(function () use ($command): WebhookSubscriptionView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::format($this->nowUtc());

            try {
                $db->transaction(fn () => $db->table($this->tables->table('webhook_subscriptions'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'target_url' => $command->targetUrl,
                    'signing_secret' => $command->signingSecret,
                    'event_types' => self::encodeJson($command->eventTypes),
                    'status' => WebhookSubscriptionStatus::Active->value,
                    'max_attempts' => $command->maxAttempts,
                    'failure_threshold' => $command->failureThreshold,
                    'consecutive_failures' => 0,
                    'disabled_at' => null,
                    'disabled_reason' => null,
                    'headers' => self::encodeObjectJson($command->headers),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateWebhookSubscription($command->code);
            }

            return $this->find(new WebhookSubscriptionId($id));
        });
    }

    #[Override]
    public function update(UpdateWebhookSubscription $command): WebhookSubscriptionView
    {
        return $this->transactions->run(function () use ($command): WebhookSubscriptionView {
            $this->lockSubscriptionRow($command->subscription);

            $changes = [];

            if ($command->targetUrl !== null) {
                $changes['target_url'] = $command->targetUrl;
            }

            if ($command->signingSecret !== null) {
                $changes['signing_secret'] = $command->signingSecret;
            }

            if ($command->eventTypes !== null) {
                $changes['event_types'] = self::encodeJson($command->eventTypes);
            }

            if ($command->maxAttempts !== null) {
                $changes['max_attempts'] = $command->maxAttempts;
            }

            if ($command->failureThreshold !== null) {
                $changes['failure_threshold'] = $command->failureThreshold;
            }

            if ($command->headers !== null) {
                $changes['headers'] = self::encodeObjectJson($command->headers);
            }

            if ($changes !== []) {
                $changes['updated_at'] = PgTimestamp::format($this->nowUtc());
                $this->db()->table($this->tables->table('webhook_subscriptions'))
                    ->where('id', $command->subscription->value)
                    ->update($changes);
            }

            return $this->find($command->subscription);
        });
    }

    #[Override]
    public function pause(WebhookSubscriptionId $id): WebhookSubscriptionView
    {
        return $this->transactions->run(function () use ($id): WebhookSubscriptionView {
            $row = $this->lockSubscriptionRow($id);

            if (Rows::string($row, 'status') !== WebhookSubscriptionStatus::Paused->value) {
                $this->db()->table($this->tables->table('webhook_subscriptions'))
                    ->where('id', $id->value)
                    ->update([
                        'status' => WebhookSubscriptionStatus::Paused->value,
                        'updated_at' => PgTimestamp::format($this->nowUtc()),
                    ]);
            }

            return $this->find($id);
        });
    }

    #[Override]
    public function enable(WebhookSubscriptionId $id): WebhookSubscriptionView
    {
        return $this->transactions->run(function () use ($id): WebhookSubscriptionView {
            $this->lockSubscriptionRow($id);

            $this->db()->table($this->tables->table('webhook_subscriptions'))
                ->where('id', $id->value)
                ->update([
                    'status' => WebhookSubscriptionStatus::Active->value,
                    'consecutive_failures' => 0,
                    'disabled_at' => null,
                    'disabled_reason' => null,
                    'updated_at' => PgTimestamp::format($this->nowUtc()),
                ]);

            return $this->find($id);
        });
    }

    #[Override]
    public function delete(WebhookSubscriptionId $id): void
    {
        $this->transactions->run(function () use ($id): void {
            $deleted = $this->db()->table($this->tables->table('webhook_subscriptions'))
                ->where('id', $id->value)
                ->delete();

            if ($deleted === 0) {
                throw new WebhookSubscriptionNotFound($id->value);
            }
        });
    }

    #[Override]
    public function find(WebhookSubscriptionId $id): WebhookSubscriptionView
    {
        $row = $this->db()->table($this->tables->table('webhook_subscriptions'))
            ->where('id', $id->value)
            ->first();

        if ($row === null) {
            throw new WebhookSubscriptionNotFound($id->value);
        }

        return WebhookRows::subscription($row);
    }

    #[Override]
    public function list(ListWebhookSubscriptions $query = new ListWebhookSubscriptions): Page
    {
        $builder = $this->db()->table($this->tables->table('webhook_subscriptions'))
            ->orderBy('code')
            ->limit($query->first + 1);

        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(WebhookRows::subscription(...), array_slice($rows, 0, $query->first));

        return new Page(
            $views,
            $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null,
        );
    }

    #[Override]
    public function enqueue(IntegrationEvent $event): array
    {
        $activeRows = $this->db()->table($this->tables->table('webhook_subscriptions'))
            ->where('status', WebhookSubscriptionStatus::Active->value)
            ->orderBy('code')
            ->get()
            ->all();

        $matching = [];

        foreach ($activeRows as $row) {
            $view = WebhookRows::subscription($row);

            if ($view->matchesEventType($event->type)) {
                $matching[] = $view;
            }
        }

        if ($matching === []) {
            return [];
        }

        return $this->transactions->run(function () use ($event, $matching): array {
            $db = $this->db();
            $deliveriesTable = $this->tables->table('webhook_deliveries');
            $now = PgTimestamp::format($this->nowUtc());
            $occurredAt = PgTimestamp::format($event->occurredAt);
            $encodedPayload = self::encodeObjectJson($event->payload);
            $results = [];

            foreach ($matching as $subscription) {
                $deliveryId = Uuid7::generate();

                try {
                    $db->transaction(fn () => $db->table($deliveriesTable)->insert([
                        'id' => $deliveryId,
                        'subscription_id' => $subscription->id->value,
                        'event_id' => $event->eventId,
                        'event_type' => $event->type,
                        'event_version' => $event->version,
                        'aggregate_ref' => $event->aggregateRef,
                        'payload' => $encodedPayload,
                        'occurred_at' => $occurredAt,
                        'status' => WebhookDeliveryStatus::Pending->value,
                        'attempts' => 0,
                        'available_at' => $now,
                        'delivered_at' => null,
                        'last_http_status' => null,
                        'last_error' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // Already enqueued for (subscription_id, event_id).
                }

                $stored = $db->table($deliveriesTable)
                    ->where('subscription_id', $subscription->id->value)
                    ->where('event_id', $event->eventId)
                    ->first();

                if ($stored !== null) {
                    $results[] = WebhookRows::delivery($stored);
                }
            }

            return $results;
        });
    }

    #[Override]
    public function enqueueFromOutbox(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidCommand('limit', 'must be from 1 to 1000', 'webhooks.invalid_batch');
        }

        $db = $this->db();
        $outboxRows = $db->table($this->tables->table('outbox_messages'))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->all();

        if ($outboxRows === []) {
            return 0;
        }

        $deliveriesTable = $this->tables->table('webhook_deliveries');
        $beforeCount = $db->table($deliveriesTable)->count();

        foreach ($outboxRows as $row) {
            $event = new IntegrationEvent(
                eventId: Rows::string($row, 'event_id'),
                type: Rows::string($row, 'type'),
                version: Rows::int($row, 'version'),
                aggregateRef: Rows::string($row, 'aggregate_ref'),
                payload: WebhookRows::mixedMap(Rows::json($row, 'payload')),
                occurredAt: Rows::date($row, 'occurred_at'),
            );
            $this->enqueue($event);
        }

        return max(0, $db->table($deliveriesTable)->count() - $beforeCount);
    }

    #[Override]
    public function dispatchPending(int $limit = 100): WebhookDispatchReport
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidCommand('limit', 'must be from 1 to 1000', 'webhooks.invalid_batch');
        }

        $db = $this->db();

        if ($db->transactionLevel() > 0) {
            throw new LogicException('Webhook delivery must run outside a transaction on the Commerz connection.');
        }

        $nowStr = PgTimestamp::format($this->nowUtc());
        $deliveriesTable = $this->tables->table('webhook_deliveries');
        $subscriptionsTable = $this->tables->table('webhook_subscriptions');

        $candidateIds = $db->table($deliveriesTable.' as d')
            ->join($subscriptionsTable.' as s', 's.id', '=', 'd.subscription_id')
            ->where('d.status', WebhookDeliveryStatus::Pending->value)
            ->where('d.available_at', '<=', $nowStr)
            ->where('s.status', WebhookSubscriptionStatus::Active->value)
            ->orderBy('d.available_at')
            ->orderBy('d.id')
            ->limit($limit)
            ->pluck('d.id')
            ->all();

        $attempted = 0;
        $delivered = 0;
        $retried = 0;
        $failed = 0;
        $autoDisabled = 0;

        foreach ($candidateIds as $candidateId) {
            if (! is_string($candidateId)) {
                continue;
            }

            $outcome = $this->attemptDelivery($candidateId, requireActiveSubscription: true);

            if ($outcome === null) {
                continue;
            }

            $attempted++;

            if ($outcome['status'] === WebhookDeliveryStatus::Delivered) {
                $delivered++;
            } elseif ($outcome['status'] === WebhookDeliveryStatus::Pending) {
                $retried++;
            } else {
                $failed++;
            }

            if ($outcome['auto_disabled']) {
                $autoDisabled++;
            }
        }

        return new WebhookDispatchReport(
            attempted: $attempted,
            delivered: $delivered,
            retried: $retried,
            failed: $failed,
            autoDisabled: $autoDisabled,
        );
    }

    #[Override]
    public function replay(ReplayWebhookDelivery $command): WebhookDeliveryView
    {
        if ($command->immediate && $this->db()->transactionLevel() > 0) {
            throw new LogicException('Immediate webhook replay must run outside a transaction on the Commerz connection.');
        }

        $this->transactions->run(function () use ($command): void {
            $deliveryRow = $this->lockDeliveryRow($command->delivery);
            $subscriptionId = new WebhookSubscriptionId(Rows::string($deliveryRow, 'subscription_id'));
            $this->lockSubscriptionRow($subscriptionId);
            $now = PgTimestamp::format($this->nowUtc());

            if ($command->reactivateSubscription) {
                $this->db()->table($this->tables->table('webhook_subscriptions'))
                    ->where('id', $subscriptionId->value)
                    ->update([
                        'status' => WebhookSubscriptionStatus::Active->value,
                        'consecutive_failures' => 0,
                        'disabled_at' => null,
                        'disabled_reason' => null,
                        'updated_at' => $now,
                    ]);
            }

            $this->db()->table($this->tables->table('webhook_deliveries'))
                ->where('id', $command->delivery->value)
                ->update([
                    'status' => WebhookDeliveryStatus::Pending->value,
                    'attempts' => 0,
                    'available_at' => $now,
                    'delivered_at' => null,
                    'last_http_status' => null,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);
        });

        if ($command->immediate) {
            $this->attemptDelivery($command->delivery->value, requireActiveSubscription: false);
        }

        return $this->findDelivery($command->delivery);
    }

    #[Override]
    public function findDelivery(WebhookDeliveryId $id): WebhookDeliveryView
    {
        $row = $this->db()->table($this->tables->table('webhook_deliveries'))
            ->where('id', $id->value)
            ->first();

        if ($row === null) {
            throw new WebhookDeliveryNotFound($id->value);
        }

        return WebhookRows::delivery($row);
    }

    #[Override]
    public function listDeliveries(ListWebhookDeliveries $query = new ListWebhookDeliveries): Page
    {
        $builder = $this->db()->table($this->tables->table('webhook_deliveries'))
            ->orderByDesc('id')
            ->limit($query->first + 1);

        if ($query->subscription !== null) {
            $builder->where('subscription_id', $query->subscription->value);
        }

        if ($query->status !== null) {
            $builder->where('status', $query->status->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('id', '<', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(WebhookRows::delivery(...), array_slice($rows, 0, $query->first));

        return new Page(
            $views,
            $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->id->value) : null,
        );
    }

    /**
     * @return array{status: WebhookDeliveryStatus, auto_disabled: bool}|null
     */
    private function attemptDelivery(string $deliveryId, bool $requireActiveSubscription): ?array
    {
        $claimed = $this->transactions->run(function () use ($deliveryId, $requireActiveSubscription): ?array {
            $db = $this->db();
            $deliveryRow = $db->table($this->tables->table('webhook_deliveries'))
                ->where('id', $deliveryId)
                ->lockForUpdate()
                ->first();

            if ($deliveryRow === null || Rows::string($deliveryRow, 'status') !== WebhookDeliveryStatus::Pending->value) {
                return null;
            }

            $nowStr = PgTimestamp::format($this->nowUtc());

            if ($requireActiveSubscription && Rows::string($deliveryRow, 'available_at') > $nowStr) {
                return null;
            }

            $subscriptionRow = $db->table($this->tables->table('webhook_subscriptions'))
                ->where('id', Rows::string($deliveryRow, 'subscription_id'))
                ->lockForUpdate()
                ->first();

            if ($subscriptionRow === null) {
                return null;
            }

            if ($requireActiveSubscription && Rows::string($subscriptionRow, 'status') !== WebhookSubscriptionStatus::Active->value) {
                return null;
            }

            $attempt = Rows::int($deliveryRow, 'attempts') + 1;

            return [
                'delivery' => WebhookRows::delivery($deliveryRow),
                'subscription' => WebhookRows::subscription($subscriptionRow),
                'signing_secret' => Rows::string($subscriptionRow, 'signing_secret'),
                'attempt' => $attempt,
            ];
        });

        if ($claimed === null) {
            return null;
        }

        $delivery = $claimed['delivery'];
        $subscription = $claimed['subscription'];
        $secret = $claimed['signing_secret'];
        $attempt = $claimed['attempt'];

        $envelope = [
            'id' => $delivery->eventId,
            'type' => $delivery->eventType,
            'version' => $delivery->eventVersion,
            'aggregate_ref' => $delivery->aggregateRef,
            'occurred_at' => $delivery->occurredAt->format('c'),
            'data' => (object) $delivery->payload,
        ];
        $rawBody = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $nowInstant = $this->nowUtc();
        $signature = WebhookSignature::sign($rawBody, $secret, $nowInstant->getTimestamp());

        $headers = array_merge($subscription->headers, [
            'Content-Type' => 'application/json',
            WebhookSignature::SIGNATURE_HEADER => $signature->toHeader(),
            WebhookSignature::EVENT_ID_HEADER => $delivery->eventId,
            WebhookSignature::EVENT_TYPE_HEADER => $delivery->eventType,
            WebhookSignature::DELIVERY_ID_HEADER => $delivery->id->value,
        ]);

        $httpStatus = null;
        $errorText = null;
        $succeeded = false;

        try {
            $response = $this->transport->send(new OutboundWebhookRequest(
                deliveryId: $delivery->id,
                url: $subscription->targetUrl,
                body: $rawBody,
                headers: $headers,
            ));
            $httpStatus = $response->statusCode;
            $succeeded = $response->isSuccessful();

            if (! $succeeded) {
                $snippet = trim(mb_substr(str_replace("\0", '', mb_scrub($response->body, 'UTF-8')), 0, 500, 'UTF-8'));
                $errorText = $snippet !== ''
                    ? "HTTP {$httpStatus}: {$snippet}"
                    : "HTTP {$httpStatus}";
            }
        } catch (Throwable $exception) {
            $errorText = mb_substr(
                str_replace("\0", '', mb_scrub($exception::class.': '.$exception->getMessage(), 'UTF-8')),
                0,
                1000,
                'UTF-8',
            );
        }

        return $this->transactions->run(function () use ($delivery, $subscription, $attempt, $succeeded, $httpStatus, $errorText): array {
            $db = $this->db();
            $nowInstant = $this->nowUtc();
            $nowStr = PgTimestamp::format($nowInstant);
            $deliveriesTable = $this->tables->table('webhook_deliveries');
            $subscriptionsTable = $this->tables->table('webhook_subscriptions');

            $lockedSub = $db->table($subscriptionsTable)->where('id', $subscription->id->value)->lockForUpdate()->first();

            if ($succeeded) {
                $db->table($deliveriesTable)->where('id', $delivery->id->value)->update([
                    'status' => WebhookDeliveryStatus::Delivered->value,
                    'attempts' => $attempt,
                    'delivered_at' => $nowStr,
                    'last_http_status' => $httpStatus,
                    'last_error' => null,
                    'updated_at' => $nowStr,
                ]);

                if ($lockedSub !== null && Rows::int($lockedSub, 'consecutive_failures') > 0) {
                    $db->table($subscriptionsTable)->where('id', $subscription->id->value)->update([
                        'consecutive_failures' => 0,
                        'updated_at' => $nowStr,
                    ]);
                }

                $updatedView = $this->findDelivery($delivery->id);
                $this->events->dispatch(new WebhookDelivered($updatedView));

                return ['status' => WebhookDeliveryStatus::Delivered, 'auto_disabled' => false];
            }

            $autoDisabled = false;

            if ($lockedSub !== null) {
                $newConsecutive = Rows::int($lockedSub, 'consecutive_failures') + 1;
                $threshold = Rows::int($lockedSub, 'failure_threshold');
                $wasActive = Rows::string($lockedSub, 'status') === WebhookSubscriptionStatus::Active->value;

                if ($wasActive && $newConsecutive >= $threshold) {
                    $autoDisabled = true;
                    $reason = "Circuit-breaker tripped after {$newConsecutive} consecutive failures ({$errorText})";
                    $db->table($subscriptionsTable)->where('id', $subscription->id->value)->update([
                        'status' => WebhookSubscriptionStatus::Disabled->value,
                        'consecutive_failures' => $newConsecutive,
                        'disabled_at' => $nowStr,
                        'disabled_reason' => mb_substr($reason, 0, 1000, 'UTF-8'),
                        'updated_at' => $nowStr,
                    ]);
                    $this->events->dispatch(new WebhookSubscriptionDisabled($this->find($subscription->id)));
                } else {
                    $db->table($subscriptionsTable)->where('id', $subscription->id->value)->update([
                        'consecutive_failures' => $newConsecutive,
                        'updated_at' => $nowStr,
                    ]);
                }
            }

            $maxAttempts = $lockedSub !== null ? Rows::int($lockedSub, 'max_attempts') : $subscription->maxAttempts;
            $subNowDisabled = $lockedSub !== null
                && ($autoDisabled || Rows::string($lockedSub, 'status') !== WebhookSubscriptionStatus::Active->value);

            if ($attempt >= $maxAttempts || $subNowDisabled) {
                $db->table($deliveriesTable)->where('id', $delivery->id->value)->update([
                    'status' => WebhookDeliveryStatus::Failed->value,
                    'attempts' => $attempt,
                    'last_http_status' => $httpStatus,
                    'last_error' => $errorText,
                    'updated_at' => $nowStr,
                ]);

                return ['status' => WebhookDeliveryStatus::Failed, 'auto_disabled' => $autoDisabled];
            }

            $nextAvailable = $this->retryPolicy->nextAvailableAt($nowInstant, $attempt);

            $db->table($deliveriesTable)->where('id', $delivery->id->value)->update([
                'status' => WebhookDeliveryStatus::Pending->value,
                'attempts' => $attempt,
                'available_at' => PgTimestamp::format($nextAvailable),
                'last_http_status' => $httpStatus,
                'last_error' => $errorText,
                'updated_at' => $nowStr,
            ]);

            return ['status' => WebhookDeliveryStatus::Pending, 'auto_disabled' => false];
        });
    }

    private function lockSubscriptionRow(WebhookSubscriptionId $id): object
    {
        $row = $this->db()->table($this->tables->table('webhook_subscriptions'))
            ->where('id', $id->value)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new WebhookSubscriptionNotFound($id->value);
        }

        return $row;
    }

    private function lockDeliveryRow(WebhookDeliveryId $id): object
    {
        $row = $this->db()->table($this->tables->table('webhook_deliveries'))
            ->where('id', $id->value)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new WebhookDeliveryNotFound($id->value);
        }

        return $row;
    }

    private function nowUtc(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->options->connection);
    }

    /** @param list<mixed> $value */
    private static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $value */
    private static function encodeObjectJson(array $value): string
    {
        return json_encode((object) $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}

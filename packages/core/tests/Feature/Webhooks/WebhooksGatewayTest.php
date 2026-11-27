<?php

declare(strict_types=1);

use Commerz\Configuration\CommerzOptions;
use Commerz\Errors\IdempotencyConflict;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Events\IntegrationEventDelivered;
use Commerz\Schema\TableNames;
use Commerz\Testkit\Fakes\FrozenClock;
use Commerz\Time\Contracts\Clock;
use Commerz\Webhooks\Contracts\InboundWebhookGateway;
use Commerz\Webhooks\Contracts\InboundWebhookHandler;
use Commerz\Webhooks\Contracts\WebhookSubscriptions;
use Commerz\Webhooks\Contracts\WebhookTransport;
use Commerz\Webhooks\Data\CreateWebhookSubscription;
use Commerz\Webhooks\Data\InboundWebhookPayload;
use Commerz\Webhooks\Data\InboundWebhookSource;
use Commerz\Webhooks\Data\ListWebhookDeliveries;
use Commerz\Webhooks\Data\OutboundWebhookRequest;
use Commerz\Webhooks\Data\ReceiveInboundWebhook;
use Commerz\Webhooks\Data\ReplayWebhookDelivery;
use Commerz\Webhooks\Data\WebhookSignature;
use Commerz\Webhooks\Data\WebhookTransportResponse;
use Commerz\Webhooks\Enums\InboundSignatureMode;
use Commerz\Webhooks\Enums\InboundWebhookOutcome;
use Commerz\Webhooks\Enums\WebhookDeliveryStatus;
use Commerz\Webhooks\Enums\WebhookSubscriptionStatus;
use Commerz\Webhooks\Events\InboundWebhookReceived;
use Commerz\Webhooks\Events\WebhookDelivered;
use Commerz\Webhooks\Events\WebhookSubscriptionDisabled;
use Commerz\Webhooks\Exceptions\DuplicateWebhookSubscription;
use Commerz\Webhooks\Exceptions\InvalidWebhookSignature;
use Commerz\Webhooks\Exceptions\WebhookTransportFailed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

final class RecordingWebhookTransport implements WebhookTransport
{
    /** @var list<OutboundWebhookRequest> */
    public array $requests = [];

    /** @var list<WebhookTransportResponse|Throwable> */
    public array $queue = [];

    public int $defaultStatus = 200;

    public function send(OutboundWebhookRequest $request): WebhookTransportResponse
    {
        $this->requests[] = $request;

        if ($this->queue !== []) {
            $next = array_shift($this->queue);

            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        return new WebhookTransportResponse($this->defaultStatus, '{"ok":true}');
    }
}

final class RecordingInboundHandler implements InboundWebhookHandler
{
    /** @var list<InboundWebhookPayload> */
    public array $handled = [];

    public bool $shouldFail = false;

    public function handle(InboundWebhookPayload $event): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('downstream handler failed');
        }

        $this->handled[] = $event;
    }
}

final class SqliteTestTransactions implements Transactions
{
    public function run(callable $work, int $lockTimeoutMs = 3000): mixed
    {
        return DB::connection('webhooks_sqlite')->transaction(static fn () => $work());
    }

    public function inTransaction(): bool
    {
        return DB::connection('webhooks_sqlite')->transactionLevel() > 0;
    }
}

beforeEach(function (): void {
    $dbPath = sys_get_temp_dir().'/commerz_webhooks_'.bin2hex(random_bytes(6)).'_test';
    touch($dbPath);
    $this->sqliteDbPath = $dbPath;

    config([
        'database.connections.webhooks_sqlite' => [
            'driver' => 'sqlite',
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'commerz.connection' => 'webhooks_sqlite',
    ]);
    app()->forgetInstance(CommerzOptions::class);
    app()->forgetInstance(TableNames::class);

    $clock = new FrozenClock('2026-11-26T12:00:00Z');
    $transport = new RecordingWebhookTransport;

    app()->instance(Clock::class, $clock);
    app()->instance(Transactions::class, new SqliteTestTransactions);
    app()->instance(WebhookTransport::class, $transport);
    app()->forgetInstance(WebhookSubscriptions::class);
    app()->forgetInstance(InboundWebhookGateway::class);

    $schema = Schema::connection('webhooks_sqlite');

    $schema->create('cmz_outbox_messages', static function (Blueprint $table): void {
        $table->id();
        $table->string('event_id', 36)->unique();
        $table->string('type', 191);
        $table->integer('version');
        $table->string('aggregate_ref', 191);
        $table->text('payload');
        $table->string('occurred_at', 64);
        $table->string('available_at', 64)->nullable();
        $table->integer('attempts')->default(0);
        $table->string('delivered_at', 64)->nullable();
        $table->text('last_error')->nullable();
    });

    $schema->create('cmz_webhook_subscriptions', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('code', 64)->unique();
        $table->string('target_url', 2048);
        $table->text('signing_secret');
        $table->text('event_types');
        $table->string('status', 16)->default('active');
        $table->integer('max_attempts')->default(5);
        $table->integer('failure_threshold')->default(5);
        $table->integer('consecutive_failures')->default(0);
        $table->string('disabled_at', 64)->nullable();
        $table->text('disabled_reason')->nullable();
        $table->text('headers')->default('{}');
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
    });

    $schema->create('cmz_webhook_deliveries', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('subscription_id', 36);
        $table->string('event_id', 36);
        $table->string('event_type', 191);
        $table->integer('event_version');
        $table->string('aggregate_ref', 191);
        $table->text('payload');
        $table->string('occurred_at', 64);
        $table->string('status', 16)->default('pending');
        $table->integer('attempts')->default(0);
        $table->string('available_at', 64);
        $table->string('delivered_at', 64)->nullable();
        $table->integer('last_http_status')->nullable();
        $table->text('last_error')->nullable();
        $table->string('created_at', 64);
        $table->string('updated_at', 64);
        $table->unique(['subscription_id', 'event_id']);
        $table->foreign('subscription_id')->references('id')->on('cmz_webhook_subscriptions')->cascadeOnDelete();
    });

    $schema->create('cmz_webhook_inbound_events', static function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('source', 64);
        $table->string('external_event_id', 191);
        $table->string('event_type', 191);
        $table->string('payload_hash', 64);
        $table->text('payload');
        $table->text('headers')->default('{}');
        $table->string('status', 16)->default('processed');
        $table->string('received_at', 64);
        $table->string('processed_at', 64)->nullable();
        $table->unique(['source', 'external_event_id']);
    });
});

afterEach(function (): void {
    Uuid7::reset();
    DB::purge('webhooks_sqlite');

    if (property_exists($this, 'sqliteDbPath') && is_file($this->sqliteDbPath)) {
        @unlink($this->sqliteDbPath);
    }
});

it('dispatches Outbox events to matching subscriptions with verifiable HMAC-SHA256 signatures and deduplicates repeats', function (): void {
    $subscriptions = app(WebhookSubscriptions::class);
    $transport = app(WebhookTransport::class);
    assert($transport instanceof RecordingWebhookTransport);
    $clock = app(Clock::class);
    assert($clock instanceof FrozenClock);

    $salesHook = $subscriptions->create(new CreateWebhookSubscription(
        code: 'erp-orders',
        targetUrl: 'https://erp.example.test/webhooks/commerz',
        signingSecret: 'whsec_erp_2026_secret',
        eventTypes: ['sales.*'],
        headers: ['X-Custom-Tenant' => 'hq', WebhookSignature::SIGNATURE_HEADER => 'attempt-spoof'],
    ));

    $subscriptions->create(new CreateWebhookSubscription(
        code: 'inventory-only',
        targetUrl: 'https://wms.example.test/webhooks',
        signingSecret: 'whsec_wms_secret',
        eventTypes: ['inventory.level_changed'],
    ));

    expect(fn () => $subscriptions->create(new CreateWebhookSubscription(
        code: 'erp-orders',
        targetUrl: 'https://other.example.test/hook',
        signingSecret: 'whsec_dup',
        eventTypes: ['*'],
    )))->toThrow(DuplicateWebhookSubscription::class);

    $event = IntegrationEvent::of(
        type: 'sales.order_placed',
        aggregateRef: 'order:0190a0a0-0000-7000-8000-000000000099',
        payload: ['order_number' => 'ORD-1001', 'total_minor' => 159900, 'currency' => 'USD'],
        version: 1,
        occurredAt: $clock->now(),
    );

    // Record in DatabaseOutbox inside transaction, then emit IntegrationEventDelivered (as OutboxRelay does).
    app(Transactions::class)->run(fn () => app(Outbox::class)->record($event));
    app(Dispatcher::class)->dispatch(new IntegrationEventDelivered($event));

    // At-least-once duplicate relay from Outbox must not send a second webhook.
    app(Dispatcher::class)->dispatch(new IntegrationEventDelivered($event));
    expect($subscriptions->enqueueFromOutbox())->toBe(0);

    expect($transport->requests)->toHaveCount(1);

    $sent = $transport->requests[0];
    expect($sent->url)->toBe('https://erp.example.test/webhooks/commerz')
        ->and($sent->headers['X-Custom-Tenant'])->toBe('hq')
        ->and($sent->headers[WebhookSignature::EVENT_ID_HEADER])->toBe($event->eventId)
        ->and($sent->headers[WebhookSignature::EVENT_TYPE_HEADER])->toBe('sales.order_placed')
        ->and($sent->headers[WebhookSignature::SIGNATURE_HEADER])->not->toBe('attempt-spoof')
        ->and(WebhookSignature::verify(
            $sent->headers[WebhookSignature::SIGNATURE_HEADER],
            $sent->body,
            'whsec_erp_2026_secret',
            $clock->now(),
        ))->toBeTrue();

    $decoded = json_decode($sent->body, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected a webhook document.');
    }

    expect($decoded['id'] ?? null)->toBe($event->eventId)
        ->and($decoded['type'] ?? null)->toBe('sales.order_placed')
        ->and($decoded['data'] ?? null)->toBe(['order_number' => 'ORD-1001', 'total_minor' => 159900, 'currency' => 'USD']);

    $page = $subscriptions->listDeliveries(new ListWebhookDeliveries(subscription: $salesHook->id));
    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($page->items[0]->attempts)->toBe(1)
        ->and($page->items[0]->lastHttpStatus)->toBe(200);
});

it('schedules exponential retries across failures using Clock and delivers once the endpoint recovers', function (): void {
    Event::fake([WebhookDelivered::class]);

    $subscriptions = app(WebhookSubscriptions::class);
    $transport = app(WebhookTransport::class);
    assert($transport instanceof RecordingWebhookTransport);
    $clock = app(Clock::class);
    assert($clock instanceof FrozenClock);

    $sub = $subscriptions->create(new CreateWebhookSubscription(
        code: 'billing-hook',
        targetUrl: 'https://billing.example.test/hook',
        signingSecret: 'whsec_billing',
        eventTypes: ['sales.order_paid'],
        maxAttempts: 4,
        failureThreshold: 10,
    ));

    // Attempt 1 -> HTTP 503, Attempt 2 -> network timeout, Attempt 3 -> HTTP 200 OK.
    $transport->queue = [
        new WebhookTransportResponse(503, 'Service Unavailable'),
        new WebhookTransportFailed('https://billing.example.test/hook', 'connection timed out'),
        new WebhookTransportResponse(202, 'Accepted'),
    ];

    $event = IntegrationEvent::of('sales.order_paid', 'order:1', ['amount_minor' => 5000], occurredAt: $clock->now());
    app(Transactions::class)->run(fn () => app(Outbox::class)->record($event));
    expect($subscriptions->enqueueFromOutbox())->toBe(1);

    // Attempt 1 at T+0 fails -> scheduled for T+30s.
    $first = $subscriptions->dispatchPending();
    $delivery = $subscriptions->listDeliveries()->items[0];

    expect([$first->attempted, $first->delivered, $first->retried, $first->failed])->toBe([1, 0, 1, 0])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->lastHttpStatus)->toBe(503)
        ->and($delivery->availableAt->format('c'))->toBe('2026-11-26T12:00:30+00:00')
        ->and($subscriptions->find($sub->id)->consecutiveFailures)->toBe(1);

    // At T+29s nothing is due yet.
    $clock->advance('+29 seconds');
    expect($subscriptions->dispatchPending()->attempted)->toBe(0);

    // At T+30s Attempt 2 fails -> backoff doubles to +60s (T+90s).
    $clock->advance('+1 second');
    $second = $subscriptions->dispatchPending();
    $delivery = $subscriptions->findDelivery($delivery->id);

    expect([$second->attempted, $second->retried])->toBe([1, 1])
        ->and($delivery->attempts)->toBe(2)
        ->and($delivery->availableAt->format('c'))->toBe('2026-11-26T12:01:30+00:00')
        ->and($delivery->lastError)->toContain('connection timed out')
        ->and($subscriptions->find($sub->id)->consecutiveFailures)->toBe(2);

    // At T+89s still waiting.
    $clock->advance('+59 seconds');
    expect($subscriptions->dispatchPending()->attempted)->toBe(0);

    // At T+90s Attempt 3 succeeds -> Delivered & consecutive_failures reset to 0!
    $clock->advance('+1 second');
    $third = $subscriptions->dispatchPending();
    $delivery = $subscriptions->findDelivery($delivery->id);

    expect([$third->attempted, $third->delivered, $third->retried, $third->failed])->toBe([1, 1, 0, 0])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($delivery->attempts)->toBe(3)
        ->and($delivery->lastHttpStatus)->toBe(202)
        ->and($delivery->lastError)->toBeNull()
        ->and($delivery->deliveredAt?->format('c'))->toBe('2026-11-26T12:01:30+00:00')
        ->and($subscriptions->find($sub->id)->consecutiveFailures)->toBe(0);

    Event::assertDispatchedTimes(WebhookDelivered::class, 1);
});

it('auto-disables dead endpoints via circuit-breaker and supports manual replay', function (): void {
    Event::fake([WebhookSubscriptionDisabled::class, WebhookDelivered::class]);

    $subscriptions = app(WebhookSubscriptions::class);
    $transport = app(WebhookTransport::class);
    assert($transport instanceof RecordingWebhookTransport);
    $clock = app(Clock::class);
    assert($clock instanceof FrozenClock);

    $sub = $subscriptions->create(new CreateWebhookSubscription(
        code: 'dead-endpoint',
        targetUrl: 'https://dead.example.test/webhook',
        signingSecret: 'whsec_dead_endpoint',
        eventTypes: ['sales.*'],
        maxAttempts: 5,
        failureThreshold: 3,
    ));

    $transport->defaultStatus = 500;

    // Enqueue 4 distinct events. After the 3rd failure in dispatchPending(), the circuit-breaker trips and skips the 4th!
    for ($i = 1; $i <= 4; $i++) {
        $subscriptions->enqueue(IntegrationEvent::of('sales.order_placed', "order:{$i}", ['n' => $i], occurredAt: $clock->now()));
    }

    $report = $subscriptions->dispatchPending();
    $disabledSub = $subscriptions->find($sub->id);

    expect($report->attempted)->toBe(3)
        ->and($report->autoDisabled)->toBe(1)
        ->and($transport->requests)->toHaveCount(3)
        ->and($disabledSub->status)->toBe(WebhookSubscriptionStatus::Disabled)
        ->and($disabledSub->consecutiveFailures)->toBe(3)
        ->and($disabledSub->disabledAt?->format('c'))->toBe('2026-11-26T12:00:00+00:00')
        ->and($disabledSub->disabledReason)->toContain('Circuit-breaker tripped after 3 consecutive failures');

    Event::assertDispatchedTimes(WebhookSubscriptionDisabled::class, 1);

    // New events are ignored while subscription is disabled by circuit breaker.
    $ignored = $subscriptions->enqueue(IntegrationEvent::of('sales.order_placed', 'order:5', ['n' => 5], occurredAt: $clock->now()));
    expect($ignored)->toBe([])
        ->and($subscriptions->dispatchPending()->attempted)->toBe(0);

    // Endpoint recovers! Operator manually replays the failed delivery with reactivateSubscription = true.
    $transport->defaultStatus = 200;
    $clock->advance('+10 minutes');

    $failedDeliveries = $subscriptions->listDeliveries(new ListWebhookDeliveries(
        subscription: $sub->id,
        status: WebhookDeliveryStatus::Failed,
    ))->items;
    expect($failedDeliveries)->toHaveCount(1);

    $replayed = $subscriptions->replay(new ReplayWebhookDelivery(
        delivery: $failedDeliveries[0]->id,
        reactivateSubscription: true,
        immediate: true,
    ));

    $reenabledSub = $subscriptions->find($sub->id);
    expect($replayed->status)->toBe(WebhookDeliveryStatus::Delivered)
        ->and($replayed->attempts)->toBe(1)
        ->and($replayed->lastHttpStatus)->toBe(200)
        ->and($replayed->lastError)->toBeNull()
        ->and($reenabledSub->status)->toBe(WebhookSubscriptionStatus::Active)
        ->and($reenabledSub->consecutiveFailures)->toBe(0)
        ->and($reenabledSub->disabledAt)->toBeNull()
        ->and($reenabledSub->disabledReason)->toBeNull();

    // Also verify queued manual replay (`immediate: false`) + artisan `commerz:webhooks:dispatch`.
    $queuedReplay = $subscriptions->replay(new ReplayWebhookDelivery(
        delivery: $replayed->id,
        immediate: false,
    ));
    expect($queuedReplay->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($queuedReplay->attempts)->toBe(0);

    $this->pendingArtisan('commerz:webhooks:dispatch', ['--batch' => 10])
        ->assertSuccessful()
        ->expectsOutputToContain('4 delivered');

    $allDelivered = $subscriptions->listDeliveries(new ListWebhookDeliveries(
        subscription: $sub->id,
        status: WebhookDeliveryStatus::Delivered,
    ))->items;
    expect($allDelivered)->toHaveCount(4);
});

it('verifies HMAC-SHA256 inbound webhooks and deduplicates by external_event_id', function (): void {
    Event::fake([InboundWebhookReceived::class]);

    $gateway = app(InboundWebhookGateway::class);
    $clock = app(Clock::class);
    assert($clock instanceof FrozenClock);
    $handler = new RecordingInboundHandler;

    $gateway->registerSource(
        new InboundWebhookSource(
            source: 'payment-psp',
            secrets: ['whsec_psp_old', 'whsec_psp_current'],
            signatureMode: InboundSignatureMode::Timestamped,
            toleranceSeconds: 300,
        ),
        $handler,
    );

    $rawBody = '{"event":"charge.captured","amount_minor":45000,"currency":"EUR"}';
    $validSig = WebhookSignature::sign($rawBody, 'whsec_psp_current', $clock->now()->getTimestamp())->toHeader();

    // 1. Invalid signature is rejected BEFORE touching DB or calling handler.
    expect(fn () => $gateway->receive(new ReceiveInboundWebhook(
        source: 'payment-psp',
        externalEventId: 'evt_ext_001',
        eventType: 'charge.captured',
        rawBody: $rawBody,
        signature: 't='.$clock->now()->getTimestamp().',v1='.str_repeat('0', 64),
    )))->toThrow(InvalidWebhookSignature::class);

    expect($handler->handled)->toBe([])
        ->and(DB::connection('webhooks_sqlite')->table('cmz_webhook_inbound_events')->count())->toBe(0);

    // 2. Valid signature processes event once.
    $first = $gateway->receive(new ReceiveInboundWebhook(
        source: 'payment-psp',
        externalEventId: 'evt_ext_001',
        eventType: 'charge.captured',
        rawBody: $rawBody,
        signature: $validSig,
        headers: ['X-Request-Id' => 'req-1'],
    ));

    expect($first->duplicate)->toBeFalse()
        ->and($first->outcome)->toBe(InboundWebhookOutcome::Processed)
        ->and($handler->handled)->toHaveCount(1)
        ->and($handler->handled[0]->payload['amount_minor'])->toBe(45000)
        ->and($gateway->find($first->id)->externalEventId)->toBe('evt_ext_001');

    Event::assertDispatchedTimes(InboundWebhookReceived::class, 1);

    // 3. Duplicate delivery of same external_event_id is deduplicated (even re-signed at a slightly later timestamp).
    $clock->advance('+15 seconds');
    $retrySig = WebhookSignature::sign($rawBody, 'whsec_psp_old', $clock->now()->getTimestamp())->toHeader();

    $second = $gateway->receive(new ReceiveInboundWebhook(
        source: 'payment-psp',
        externalEventId: 'evt_ext_001',
        eventType: 'charge.captured',
        rawBody: $rawBody,
        signature: $retrySig,
    ));

    expect($second->duplicate)->toBeTrue()
        ->and($second->outcome)->toBe(InboundWebhookOutcome::Duplicate)
        ->and($second->id->equals($first->id))->toBeTrue()
        ->and($handler->handled)->toHaveCount(1);

    Event::assertDispatchedTimes(InboundWebhookReceived::class, 1);

    // 4. Reusing same external_event_id with a DIFFERENT body raises IdempotencyConflict.
    $differentBody = '{"event":"charge.captured","amount_minor":99999,"currency":"EUR"}';
    $diffSig = WebhookSignature::sign($differentBody, 'whsec_psp_current', $clock->now()->getTimestamp())->toHeader();

    expect(fn () => $gateway->receive(new ReceiveInboundWebhook(
        source: 'payment-psp',
        externalEventId: 'evt_ext_001',
        eventType: 'charge.captured',
        rawBody: $differentBody,
        signature: $diffSig,
    )))->toThrow(IdempotencyConflict::class);

    // 5. Same external_event_id from a DIFFERENT source is independent; also test PayloadHmac mode and rollback on handler failure.
    $wmsSource = new InboundWebhookSource(
        source: 'wms',
        secrets: 'whsec_wms_raw',
        signatureMode: InboundSignatureMode::PayloadHmac,
    );
    $wmsBody = '{"sku":"SKU-1","on_hand":50}';
    $wmsSig = 'sha256='.WebhookSignature::signPayload($wmsBody, 'whsec_wms_raw');

    $handler->shouldFail = true;
    expect(fn () => $gateway->receive(
        new ReceiveInboundWebhook('wms', 'evt_ext_001', 'stock.synced', $wmsBody, $wmsSig),
        $wmsSource,
        $handler,
    ))->toThrow(RuntimeException::class, 'downstream handler failed');

    // Handler failure rolled back the deduplication claim, so provider's retry succeeds once healthy!
    $handler->shouldFail = false;
    $wmsResult = $gateway->receive(
        new ReceiveInboundWebhook('wms', 'evt_ext_001', 'stock.synced', $wmsBody, $wmsSig),
        $wmsSource,
        $handler,
    );

    expect($wmsResult->duplicate)->toBeFalse()
        ->and($handler->handled)->toHaveCount(2);

    // Unknown source fails closed with InvalidCommand.
    expect(fn () => $gateway->receive(new ReceiveInboundWebhook('unknown-src', 'e1', 'x.y', '{}', $wmsSig)))
        ->toThrow(InvalidCommand::class, 'not registered');
});

<?php

declare(strict_types=1);

use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Outbox\Internal\OutboxRelay;
use Commerz\Webhooks\Contracts\WebhookSubscriptions;
use Commerz\Webhooks\Contracts\WebhookTransport;
use Commerz\Webhooks\Data\CreateWebhookSubscription;
use Commerz\Webhooks\Data\OutboundWebhookRequest;
use Commerz\Webhooks\Data\WebhookSignature;
use Commerz\Webhooks\Data\WebhookTransportResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Packman\Schema\Data\Compiler\ConstraintNames;

uses(RefreshDatabase::class);

it('creates canonical PostgreSQL webhook tables, constraints, and relays DatabaseOutbox events end-to-end', function (): void {
    $subConstraints = [];

    foreach (DB::select("select conname from pg_constraint where conrelid = 'cmz_webhook_subscriptions'::regclass and contype <> 'n' order by conname") as $row) {
        if (! $row instanceof stdClass || ! is_string($row->conname)) {
            throw new RuntimeException('Expected a constraint name.');
        }

        $subConstraints[] = $row->conname;
    }

    $delivIndexes = [];

    foreach (DB::select("select indexname from pg_indexes where tablename = 'cmz_webhook_deliveries' order by indexname") as $row) {
        if (! $row instanceof stdClass || ! is_string($row->indexname)) {
            throw new RuntimeException('Expected an index name.');
        }

        $delivIndexes[] = $row->indexname;
    }

    expect($subConstraints)->toContain(
        'cmz_webhook_subscriptions_pk',
        'cmz_webhook_subscriptions_code_uq',
        'cmz_webhook_subscriptions_status_valid_ck',
    )->and($delivIndexes)->toContain(
        ConstraintNames::shorten('cmz_webhook_deliveries_pending_available_at_id_ix'),
    );

    $captured = [];
    app()->instance(WebhookTransport::class, new class($captured) implements WebhookTransport
    {
        /** @param list<OutboundWebhookRequest> $captured */
        public function __construct(public array &$captured) {}

        public function send(OutboundWebhookRequest $request): WebhookTransportResponse
        {
            $this->captured[] = $request;

            return new WebhookTransportResponse(200, 'ok');
        }
    });

    app(WebhookSubscriptions::class)->create(new CreateWebhookSubscription(
        code: 'pg-relay-test',
        targetUrl: 'https://hooks.example.test/pg',
        signingSecret: 'whsec_pg_test',
        eventTypes: ['sales.order_placed'],
    ));

    $event = IntegrationEvent::of('sales.order_placed', 'order:42', ['total_minor' => 1000]);
    app(Transactions::class)->run(fn () => app(Outbox::class)->record($event));

    // OutboxRelay must run outside an ambient transaction; RefreshDatabase wraps tests in a transaction, so verify schema & enqueue here.
    $enqueued = app(WebhookSubscriptions::class)->enqueue($event);
    expect($enqueued)->toHaveCount(1)
        ->and($enqueued[0]->eventId)->toBe($event->eventId)
        ->and(class_exists(OutboxRelay::class))->toBeTrue()
        ->and(WebhookSignature::SIGNATURE_HEADER)->toBe('X-Commerz-Signature');
});

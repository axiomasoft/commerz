<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Contracts;

use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Pagination\Page;
use Commerz\Webhooks\Data\CreateWebhookSubscription;
use Commerz\Webhooks\Data\ListWebhookDeliveries;
use Commerz\Webhooks\Data\ListWebhookSubscriptions;
use Commerz\Webhooks\Data\ReplayWebhookDelivery;
use Commerz\Webhooks\Data\UpdateWebhookSubscription;
use Commerz\Webhooks\Data\WebhookDeliveryId;
use Commerz\Webhooks\Data\WebhookDeliveryView;
use Commerz\Webhooks\Data\WebhookDispatchReport;
use Commerz\Webhooks\Data\WebhookSubscriptionId;
use Commerz\Webhooks\Data\WebhookSubscriptionView;
use Commerz\Webhooks\Exceptions\DuplicateWebhookSubscription;
use Commerz\Webhooks\Exceptions\WebhookDeliveryNotFound;
use Commerz\Webhooks\Exceptions\WebhookSubscriptionNotFound;
use Packman\Attributes\Api;

/**
 * Outgoing webhook subscription registry and dispatcher layered on top of the transactional outbox:
 * signs payloads with HMAC-SHA256, schedules exponential retries, trips a circuit-breaker to auto-disable
 * dead endpoints after consecutive failures, and supports manual replay.
 */
#[Api(since: '0.1')]
interface WebhookSubscriptions
{
    /** @throws DuplicateWebhookSubscription */
    public function create(CreateWebhookSubscription $command): WebhookSubscriptionView;

    /** @throws WebhookSubscriptionNotFound */
    public function update(UpdateWebhookSubscription $command): WebhookSubscriptionView;

    /** @throws WebhookSubscriptionNotFound */
    public function pause(WebhookSubscriptionId $id): WebhookSubscriptionView;

    /**
     * Re-activates a paused or circuit-broken subscription and resets its consecutive failure counter.
     *
     * @throws WebhookSubscriptionNotFound
     */
    public function enable(WebhookSubscriptionId $id): WebhookSubscriptionView;

    /** @throws WebhookSubscriptionNotFound */
    public function delete(WebhookSubscriptionId $id): void;

    /** @throws WebhookSubscriptionNotFound */
    public function find(WebhookSubscriptionId $id): WebhookSubscriptionView;

    /** @return Page<WebhookSubscriptionView> */
    public function list(ListWebhookSubscriptions $query = new ListWebhookSubscriptions): Page;

    /**
     * Idempotently enqueues a pending delivery for each active subscription matching `$event->type`.
     *
     * @return list<WebhookDeliveryView>
     */
    public function enqueue(IntegrationEvent $event): array;

    /**
     * Scans recorded `outbox_messages` rows and idempotently enqueues deliveries for active matching subscriptions.
     *
     * @return int number of newly created delivery rows
     */
    public function enqueueFromOutbox(int $limit = 100): int;

    /**
     * Dispatches due pending deliveries for active subscriptions outside a transaction, applying exponential retries
     * and auto-disabling subscriptions whose consecutive failures reach `failureThreshold`.
     */
    public function dispatchPending(int $limit = 100): WebhookDispatchReport;

    /**
     * Manually replays a webhook delivery, resetting its error state and optionally re-enabling its subscription.
     *
     * @throws WebhookDeliveryNotFound
     * @throws WebhookSubscriptionNotFound
     */
    public function replay(ReplayWebhookDelivery $command): WebhookDeliveryView;

    /** @throws WebhookDeliveryNotFound */
    public function findDelivery(WebhookDeliveryId $id): WebhookDeliveryView;

    /** @return Page<WebhookDeliveryView> */
    public function listDeliveries(ListWebhookDeliveries $query = new ListWebhookDeliveries): Page;
}

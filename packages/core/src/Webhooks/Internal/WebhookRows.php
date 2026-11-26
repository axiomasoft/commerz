<?php

declare(strict_types=1);

namespace Commerz\Webhooks\Internal;

use Commerz\Schema\Rows;
use Commerz\Webhooks\Data\InboundWebhookEventId;
use Commerz\Webhooks\Data\InboundWebhookPayload;
use Commerz\Webhooks\Data\WebhookDeliveryId;
use Commerz\Webhooks\Data\WebhookDeliveryView;
use Commerz\Webhooks\Data\WebhookSubscriptionId;
use Commerz\Webhooks\Data\WebhookSubscriptionView;
use Commerz\Webhooks\Enums\WebhookDeliveryStatus;
use Commerz\Webhooks\Enums\WebhookSubscriptionStatus;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final class WebhookRows
{
    public static function subscription(object $row): WebhookSubscriptionView
    {
        $rawTypes = Rows::json($row, 'event_types');
        $eventTypes = [];

        foreach ($rawTypes as $type) {
            if (! is_string($type)) {
                throw new RuntimeException("Column 'event_types' holds a non-string entry.");
            }

            $eventTypes[] = $type;
        }

        $disabledAt = Rows::nullableString($row, 'disabled_at');

        return new WebhookSubscriptionView(
            id: new WebhookSubscriptionId(Rows::string($row, 'id')),
            code: Rows::string($row, 'code'),
            targetUrl: Rows::string($row, 'target_url'),
            eventTypes: $eventTypes,
            status: WebhookSubscriptionStatus::from(Rows::string($row, 'status')),
            maxAttempts: Rows::int($row, 'max_attempts'),
            failureThreshold: Rows::int($row, 'failure_threshold'),
            consecutiveFailures: Rows::int($row, 'consecutive_failures'),
            disabledAt: $disabledAt === null ? null : new DateTimeImmutable($disabledAt),
            disabledReason: Rows::nullableString($row, 'disabled_reason'),
            headers: self::stringMap(Rows::json($row, 'headers')),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    public static function delivery(object $row): WebhookDeliveryView
    {
        $deliveredAt = Rows::nullableString($row, 'delivered_at');

        return new WebhookDeliveryView(
            id: new WebhookDeliveryId(Rows::string($row, 'id')),
            subscriptionId: new WebhookSubscriptionId(Rows::string($row, 'subscription_id')),
            eventId: Rows::string($row, 'event_id'),
            eventType: Rows::string($row, 'event_type'),
            eventVersion: Rows::int($row, 'event_version'),
            aggregateRef: Rows::string($row, 'aggregate_ref'),
            payload: self::mixedMap(Rows::json($row, 'payload')),
            occurredAt: Rows::date($row, 'occurred_at'),
            status: WebhookDeliveryStatus::from(Rows::string($row, 'status')),
            attempts: Rows::int($row, 'attempts'),
            availableAt: Rows::date($row, 'available_at'),
            deliveredAt: $deliveredAt === null ? null : new DateTimeImmutable($deliveredAt),
            lastHttpStatus: Rows::nullableInt($row, 'last_http_status'),
            lastError: Rows::nullableString($row, 'last_error'),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    public static function inboundPayload(object $row): InboundWebhookPayload
    {
        return new InboundWebhookPayload(
            id: new InboundWebhookEventId(Rows::string($row, 'id')),
            source: Rows::string($row, 'source'),
            externalEventId: Rows::string($row, 'external_event_id'),
            eventType: Rows::string($row, 'event_type'),
            payload: self::mixedMap(Rows::json($row, 'payload')),
            headers: self::stringMap(Rows::json($row, 'headers')),
            receivedAt: Rows::date($row, 'received_at'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function mixedMap(array $raw): array
    {
        $result = [];

        foreach ($raw as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, string>
     */
    public static function stringMap(array $raw): array
    {
        $result = [];

        foreach ($raw as $key => $value) {
            if (! is_string($value)) {
                throw new RuntimeException('Expected string map value in JSON column.');
            }

            $result[(string) $key] = $value;
        }

        return $result;
    }
}

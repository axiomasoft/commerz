<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Webhooks\Data\CreateWebhookSubscription;
use Commerz\Webhooks\Data\InboundWebhookSource;
use Commerz\Webhooks\Data\ReceiveInboundWebhook;
use Commerz\Webhooks\Data\WebhookRetryPolicy;
use Commerz\Webhooks\Data\WebhookSignature;
use Commerz\Webhooks\Data\WebhookSubscriptionId;
use Commerz\Webhooks\Data\WebhookSubscriptionView;
use Commerz\Webhooks\Enums\WebhookSubscriptionStatus;
use Commerz\Webhooks\Exceptions\InvalidWebhookSignature;

it('signs and verifies timestamped HMAC-SHA256 webhook headers with replay window enforcement and secret rotation', function (): void {
    $body = '{"id":"0190a0a0-0000-7000-8000-000000000001","type":"sales.order_placed"}';
    $secret = 'whsec_primary_secret_key_01';
    $timestamp = 1764154000;
    $now = new DateTimeImmutable('@1764154120');

    $signature = WebhookSignature::sign($body, $secret, $timestamp);
    $header = $signature->toHeader();

    expect($header)->toStartWith('t=1764154000,v1=')
        ->and(WebhookSignature::verify($header, $body, $secret, $now, toleranceSeconds: 300))->toBeTrue()
        ->and(WebhookSignature::verify($header, $body, ['whsec_old_retired', $secret], $now, toleranceSeconds: 300))->toBeTrue()
        ->and(WebhookSignature::verify($header, $body.' ', $secret, $now, toleranceSeconds: 300))->toBeFalse()
        ->and(WebhookSignature::verify($header, $body, 'whsec_wrong', $now, toleranceSeconds: 300))->toBeFalse();

    // Outside replay window (301s skew with 300s tolerance).
    $expiredNow = new DateTimeImmutable('@1764154301');
    expect(WebhookSignature::verify($header, $body, $secret, $expiredNow, toleranceSeconds: 300))->toBeFalse();
    expect(fn () => WebhookSignature::assertValid($header, $body, $secret, $expiredNow, 300, 'erp'))
        ->toThrow(InvalidWebhookSignature::class, 'replay window');

    // Dual v1 signatures during active key rollover.
    $oldSig = WebhookSignature::sign($body, 'whsec_v0', $timestamp)->signatures[0];
    $newSig = WebhookSignature::sign($body, 'whsec_v1', $timestamp)->signatures[0];
    $dualHeader = "t={$timestamp},v1={$oldSig},v1={$newSig}";

    expect(WebhookSignature::verify($dualHeader, $body, 'whsec_v1', $now, 300))->toBeTrue()
        ->and(WebhookSignature::parse($dualHeader)->signatures)->toHaveCount(2);
});

it('signs and verifies payload-only HMAC-SHA256 signatures', function (): void {
    $body = '{"external_id":"evt_99","status":"paid"}';
    $digest = WebhookSignature::signPayload($body, 'shared_secret');

    expect(WebhookSignature::verifyPayload($digest, $body, 'shared_secret'))->toBeTrue()
        ->and(WebhookSignature::verifyPayload('sha256='.strtoupper($digest), $body, ['other', 'shared_secret']))->toBeTrue()
        ->and(WebhookSignature::verifyPayload('sha256='.$digest, '{"tampered":true}', 'shared_secret'))->toBeFalse()
        ->and(fn () => WebhookSignature::assertPayloadValid('not-hex', $body, 'shared_secret', 'psp'))
        ->toThrow(InvalidWebhookSignature::class, '64-character hex');
});

it('computes exponential retry delays and caps at maxDelaySeconds', function (): void {
    $policy = WebhookRetryPolicy::exponential(baseDelaySeconds: 30, maxDelaySeconds: 3600);
    $delays = array_map($policy->delaySecondsForAttempt(...), [1, 2, 3, 4, 5, 7, 8, 25]);

    expect($delays)->toBe([30, 60, 120, 240, 480, 1920, 3600, 3600]);

    $base = new DateTimeImmutable('2026-11-26T10:00:00Z');
    expect($policy->nextAvailableAt($base, 1)->format('c'))->toBe('2026-11-26T10:00:30+00:00')
        ->and($policy->nextAvailableAt($base, 3)->format('c'))->toBe('2026-11-26T10:02:00+00:00');

    expect(fn () => new WebhookRetryPolicy(0, 60))->toThrow(InvalidCommand::class, 'baseDelaySeconds')
        ->and(fn () => new WebhookRetryPolicy(120, 60))->toThrow(InvalidCommand::class, 'maxDelaySeconds')
        ->and(fn () => $policy->delaySecondsForAttempt(0))->toThrow(InvalidCommand::class, 'attempt');
});

it('validates subscription patterns, URLs, and inbound webhook commands', function (): void {
    $now = new DateTimeImmutable('2026-11-26T10:00:00Z');
    $view = new WebhookSubscriptionView(
        id: WebhookSubscriptionId::generate(),
        code: 'warehouse-hook',
        targetUrl: 'https://hooks.example.test/events',
        eventTypes: ['sales.order_placed', 'inventory.*'],
        status: WebhookSubscriptionStatus::Active,
        maxAttempts: 5,
        failureThreshold: 5,
        consecutiveFailures: 0,
        disabledAt: null,
        disabledReason: null,
        headers: [],
        createdAt: $now,
        updatedAt: $now,
    );

    expect($view->matchesEventType('sales.order_placed'))->toBeTrue()
        ->and($view->matchesEventType('sales.order_cancelled'))->toBeFalse()
        ->and($view->matchesEventType('inventory.level_changed'))->toBeTrue()
        ->and($view->matchesEventType('catalog.product_updated'))->toBeFalse();

    expect(fn () => new CreateWebhookSubscription('Bad Code', 'https://hooks.example.test', 'sec', ['*']))
        ->toThrow(InvalidCommand::class, 'code')
        ->and(fn () => new CreateWebhookSubscription('ok', 'ftp://hooks.example.test', 'sec', ['*']))
        ->toThrow(InvalidCommand::class, 'targetUrl')
        ->and(fn () => new CreateWebhookSubscription('ok', 'https://hooks.example.test', '', ['*']))
        ->toThrow(InvalidCommand::class, 'signingSecret')
        ->and(fn () => new CreateWebhookSubscription('ok', 'https://hooks.example.test', 'sec', ['InvalidEvent']))
        ->toThrow(InvalidCommand::class, 'eventTypes')
        ->and(fn () => new InboundWebhookSource('erp', []))->toThrow(InvalidCommand::class, 'secrets')
        ->and(fn () => new ReceiveInboundWebhook('erp', '', 'evt.paid', '{}', 'sig'))
        ->toThrow(InvalidCommand::class, 'externalEventId');
});

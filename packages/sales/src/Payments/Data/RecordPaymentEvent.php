<?php

declare(strict_types=1);

namespace Commerz\Sales\Payments\Data;

use Commerz\Money\Money;
use Commerz\Sales\Payments\Enums\PaymentEventType;
use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * An event of a provider about a payment (PAY-01), recorded once per `(gateway, externalEventId)`. The adapter passes
 * the provider's event id, or a semantic key where it has none (`payment.succeeded:{object.id}` for YooKassa,
 * `eventCode:pspReference` for Adyen). The payment is found by our `paymentReference` (`payment:{id}`) or by the
 * provider's `externalPaymentId`; at least one is required, and both may be given.
 */
#[Api(since: '0.1')]
final readonly class RecordPaymentEvent
{
    /**
     * @param  Money|null  $amount  the amount authorized or captured, when the provider reports it; the whole payment otherwise
     * @param  array<string, scalar>  $payload  what the adapter wants kept with the event
     */
    public function __construct(
        public string $gateway,
        public string $externalEventId,
        public PaymentEventType $type,
        public ?string $paymentReference = null,
        public ?string $externalPaymentId = null,
        public ?Money $amount = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $payload = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $gateway) !== 1) {
            throw new InvalidPaymentEvent('gateway', 'must be a contribution key of 1..64 characters [a-z0-9_.-]');
        }

        foreach (['externalEventId' => $externalEventId, 'paymentReference' => $paymentReference, 'externalPaymentId' => $externalPaymentId] as $field => $value) {
            if ($value !== null && ! self::isText($value)) {
                throw new InvalidPaymentEvent($field, 'must be 1..191 bytes of UTF-8 without NUL');
            }
        }

        if ($paymentReference === null && $externalPaymentId === null) {
            throw new InvalidPaymentEvent('paymentReference', 'or externalPaymentId is required');
        }

        if ($amount !== null && ! $amount->isPositive()) {
            throw new InvalidPaymentEvent('amount', 'must be positive');
        }

        self::assertPayload($payload);
    }

    /** @param  array<array-key, mixed>  $payload  checked for callers without static analysis */
    private static function assertPayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (! is_string($key) || $key === '' || ! is_scalar($value)
                || str_contains($key, "\0") || (is_string($value) && str_contains($value, "\0"))
                || json_encode($value) === false) {
                throw new InvalidPaymentEvent('payload', 'must map non-empty string keys to JSON scalars without NUL');
            }
        }
    }

    private static function isText(string $value): bool
    {
        return $value !== '' && strlen($value) <= 191 && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }
}

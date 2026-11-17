<?php

declare(strict_types=1);

namespace Commerz\Sales\Refunds\Data;

use Commerz\Sales\Payments\Exceptions\InvalidPaymentEvent;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * An event of a provider about a refund, recorded once per `(gateway, externalEventId)` in the same inbox as payment
 * events (PAY-01). The refund is found by our `refundReference` (`refund:{id}`) or by the provider's
 * `externalRefundId`; at least one is required, and both may be given.
 */
#[Api(since: '0.1')]
final readonly class RecordRefundEvent
{
    /**
     * @param  bool  $succeeded  the money went back (true) or the provider gave up the refund (false)
     * @param  array<string, scalar>  $payload  what the adapter wants kept with the event
     */
    public function __construct(
        public string $gateway,
        public string $externalEventId,
        public bool $succeeded,
        public ?string $refundReference = null,
        public ?string $externalRefundId = null,
        public ?DateTimeImmutable $occurredAt = null,
        public array $payload = [],
    ) {
        if (preg_match('/\A[a-z0-9][a-z0-9_.-]{0,63}\z/', $gateway) !== 1) {
            throw new InvalidPaymentEvent('gateway', 'must be a contribution key of 1..64 characters [a-z0-9_.-]');
        }

        foreach (['externalEventId' => $externalEventId, 'refundReference' => $refundReference, 'externalRefundId' => $externalRefundId] as $field => $value) {
            if ($value !== null && ! self::isText($value)) {
                throw new InvalidPaymentEvent($field, 'must be 1..191 bytes of UTF-8 without NUL');
            }
        }

        if ($refundReference === null && $externalRefundId === null) {
            throw new InvalidPaymentEvent('refundReference', 'or externalRefundId is required');
        }

        self::assertPayload($payload);
    }

    /** The type the inbox records the event under. */
    public function type(): string
    {
        return $this->succeeded ? 'refund_succeeded' : 'refund_failed';
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

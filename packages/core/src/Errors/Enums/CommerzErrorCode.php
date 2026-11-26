<?php

declare(strict_types=1);

namespace Commerz\Errors\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz`.
 */
#[Api(since: '0.1')]
enum CommerzErrorCode: string implements ErrorCode
{
    case AmountOverflow = 'commerz.amount_overflow';
    case ChannelArchived = 'commerz.channel_archived';
    case ChannelNotFound = 'commerz.channel_not_found';
    case CurrencyMismatch = 'commerz.currency_mismatch';
    case CurrencyNotAllowed = 'commerz.currency_not_allowed';
    case DuplicateCode = 'commerz.duplicate_code';
    case DuplicateWebhookSubscription = 'commerz.duplicate_webhook_subscription';
    case IdempotencyConflict = 'commerz.idempotency_conflict';
    case InsufficientStock = 'commerz.insufficient_stock';
    case InsufficientStoredValue = 'commerz.insufficient_stored_value';
    case InvalidAmount = 'commerz.invalid_amount';
    case InvalidCommand = 'commerz.invalid_command';
    case InvalidConfiguration = 'commerz.invalid_configuration';
    case InvalidCurrencies = 'commerz.invalid_currencies';
    case InvalidCurrency = 'commerz.invalid_currency';
    case InvalidId = 'commerz.invalid_id';
    case InvalidIdempotencyKey = 'commerz.invalid_idempotency_key';
    case InvalidLocale = 'commerz.invalid_locale';
    case InvalidOutboxEvent = 'commerz.invalid_outbox_event';
    case InvalidPageSize = 'commerz.invalid_page_size';
    case InvalidRate = 'commerz.invalid_rate';
    case InvalidRef = 'commerz.invalid_ref';
    case InvalidWebhookSignature = 'commerz.invalid_webhook_signature';
    case InvalidWeights = 'commerz.invalid_weights';
    case NotFound = 'commerz.not_found';
    case PriceNotFound = 'commerz.price_not_found';
    case QuoteCurrencyMismatch = 'commerz.quote_currency_mismatch';
    case QuoteFailed = 'commerz.quote_failed';
    case ReservationNotFound = 'commerz.reservation_not_found';
    case SellableUnavailable = 'commerz.sellable_unavailable';
    case StoreNotFound = 'commerz.store_not_found';
    case StoredValueNotFound = 'commerz.stored_value_not_found';
    case StoredValueUnavailable = 'commerz.stored_value_unavailable';
    case UnsupportedContribution = 'commerz.unsupported_contribution';
    case UsageLimitReached = 'commerz.usage_limit_reached';
    case WebhookDeliveryNotFound = 'commerz.webhook_delivery_not_found';
    case WebhookSubscriptionNotFound = 'commerz.webhook_subscription_not_found';
    case WebhookTransportFailed = 'commerz.webhook_transport_failed';

    public function value(): string
    {
        return $this->value;
    }
}

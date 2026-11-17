<?php

declare(strict_types=1);

namespace Commerz\Shipping\Errors;

use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Registers RFC 9457 Problem Details definitions for all `axiomasoft/commerz-shipping` error codes.
 */
#[Api(since: '0.1')]
final readonly class ShippingProblemDefinitions implements ProblemDefinitionProvider
{
    public function __construct(
        private string $urnPrefix = 'urn:packman:problem:',
    ) {}

    /** @return list<ProblemDefinition> */
    #[Override]
    public function definitions(): array
    {
        return [
            $this->define(ShippingErrorCode::BookingInProgress, 409, 'Shipment Booking In Progress', ErrorCategory::Conflict, ['reference']),
            $this->define(ShippingErrorCode::BookingInsideTransaction, 422, 'Booking Inside Database Transaction', ErrorCategory::Validation, ['reference']),
            $this->define(ShippingErrorCode::BookingNotFound, 404, 'Shipment Booking Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(ShippingErrorCode::BookingOutcomeUnknown, 503, 'Shipment Booking Outcome Unknown', ErrorCategory::Unavailable, ['reference', 'carrier']),
            $this->define(ShippingErrorCode::CarrierMismatch, 422, 'Shipping Carrier Mismatch', ErrorCategory::Validation, ['field', 'reason', 'method', 'expected', 'given']),
            $this->define(ShippingErrorCode::CarrierRejected, 503, 'Shipping Carrier Rejected Booking', ErrorCategory::Unavailable, ['carrier', 'reason', 'detail']),
            $this->define(ShippingErrorCode::CurrencyMismatch, 422, 'Shipping Currency Mismatch', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::DeliveryUnavailable, 409, 'Delivery Method Unavailable', ErrorCategory::Conflict, ['method', 'verdict']),
            $this->define(ShippingErrorCode::DuplicateCode, 409, 'Duplicate Shipping Code', ErrorCategory::Conflict, ['entity', 'code']),
            $this->define(ShippingErrorCode::DuplicateRate, 422, 'Duplicate Zone Currency Rate', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::DuplicateZoneMember, 422, 'Duplicate Shipping Zone Member', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidBooking, 422, 'Invalid Carrier Booking Request', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidCarrierResult, 503, 'Invalid Carrier Booking Result', ErrorCategory::Unavailable, ['carrier', 'reason']),
            $this->define(ShippingErrorCode::InvalidChannels, 422, 'Invalid Shipping Channels', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidCode, 422, 'Invalid Shipping Code', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidEta, 422, 'Invalid Delivery ETA Days', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidFreeAbove, 422, 'Invalid Free Shipping Threshold', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidName, 422, 'Invalid Shipping Name', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidPosition, 422, 'Invalid Shipping Position', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidRate, 422, 'Invalid Delivery Rate Key', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidRateConfig, 422, 'Invalid Delivery Rate Config', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidRatePrice, 422, 'Invalid Delivery Rate Price', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidRateResult, 503, 'Invalid Delivery Rate Result', ErrorCategory::Unavailable, ['rate', 'reason']),
            $this->define(ShippingErrorCode::InvalidStatus, 422, 'Invalid Shipping Method Status', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::InvalidZoneMember, 422, 'Invalid Shipping Zone Member', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::MethodArchived, 409, 'Shipping Method Archived', ErrorCategory::Conflict, ['method']),
            $this->define(ShippingErrorCode::MethodNotFound, 404, 'Shipping Method Not Found', ErrorCategory::NotFound, ['entity', 'id']),
            $this->define(ShippingErrorCode::TooManyRates, 422, 'Too Many Shipping Method Rates', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::TooManyZoneMembers, 422, 'Too Many Shipping Zone Members', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::UnknownCarrier, 422, 'Unknown Shipping Carrier', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::UnknownRate, 422, 'Unknown Delivery Rate', ErrorCategory::Validation, ['*']),
            $this->define(ShippingErrorCode::ZoneArchived, 409, 'Shipping Zone Archived', ErrorCategory::Conflict, ['zone']),
            $this->define(ShippingErrorCode::ZoneNotFound, 404, 'Shipping Zone Not Found', ErrorCategory::NotFound, ['entity', 'id']),
        ];
    }

    /** @param list<string> $detailKeys */
    private function define(
        ShippingErrorCode $code,
        int $defaultStatus,
        string $title,
        ErrorCategory $category,
        array $detailKeys = [],
    ): ProblemDefinition {
        return new ProblemDefinition(
            code: $code,
            type: $this->urnPrefix.$code->value(),
            defaultStatus: $defaultStatus,
            title: $title,
            retryable: false,
            category: $category,
            detailKeys: $detailKeys,
        );
    }
}

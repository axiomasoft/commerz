<?php

declare(strict_types=1);

namespace Commerz\Shipping\Errors\Enums;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

/**
 * Stable wire-level error codes emitted by `axiomasoft/commerz-shipping`.
 */
#[Api(since: '0.1')]
enum ShippingErrorCode: string implements ErrorCode
{
    case BookingInProgress = 'shipping.booking_in_progress';
    case BookingInsideTransaction = 'shipping.booking_inside_transaction';
    case BookingNotFound = 'shipping.booking_not_found';
    case BookingOutcomeUnknown = 'shipping.booking_outcome_unknown';
    case CarrierMismatch = 'shipping.carrier_mismatch';
    case CarrierRejected = 'shipping.carrier_rejected';
    case CurrencyMismatch = 'shipping.currency_mismatch';
    case DeliveryUnavailable = 'shipping.delivery_unavailable';
    case DuplicateCode = 'shipping.duplicate_code';
    case DuplicateRate = 'shipping.duplicate_rate';
    case DuplicateZoneMember = 'shipping.duplicate_zone_member';
    case InvalidBooking = 'shipping.invalid_booking';
    case InvalidCarrierResult = 'shipping.invalid_carrier_result';
    case InvalidChannels = 'shipping.invalid_channels';
    case InvalidCode = 'shipping.invalid_code';
    case InvalidEta = 'shipping.invalid_eta';
    case InvalidFreeAbove = 'shipping.invalid_free_above';
    case InvalidName = 'shipping.invalid_name';
    case InvalidPacking = 'shipping.invalid_packing';
    case InvalidPickupPoint = 'shipping.invalid_pickup_point';
    case InvalidPosition = 'shipping.invalid_position';
    case InvalidRate = 'shipping.invalid_rate';
    case InvalidRateConfig = 'shipping.invalid_rate_config';
    case InvalidRatePrice = 'shipping.invalid_rate_price';
    case InvalidRateResult = 'shipping.invalid_rate_result';
    case InvalidStatus = 'shipping.invalid_status';
    case InvalidZoneMember = 'shipping.invalid_zone_member';
    case MethodArchived = 'shipping.method_archived';
    case MethodNotFound = 'shipping.method_not_found';
    case PickupPointNotFound = 'shipping.pickup_point_not_found';
    case PickupPointOversized = 'shipping.pickup_point_oversized';
    case TooManyRates = 'shipping.too_many_rates';
    case TooManyZoneMembers = 'shipping.too_many_zone_members';
    case UnknownCarrier = 'shipping.unknown_carrier';
    case UnknownRate = 'shipping.unknown_rate';
    case UnpackableItem = 'shipping.unpackable_item';
    case ZoneArchived = 'shipping.zone_archived';
    case ZoneNotFound = 'shipping.zone_not_found';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }
}

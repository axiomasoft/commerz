<?php

declare(strict_types=1);

use Commerz\Errors\CommerzException;
use Commerz\Shipping\Bookings\Exceptions\BookingInProgress;
use Commerz\Shipping\Bookings\Exceptions\BookingInsideTransaction;
use Commerz\Shipping\Bookings\Exceptions\BookingNotFound;
use Commerz\Shipping\Bookings\Exceptions\BookingOutcomeUnknown;
use Commerz\Shipping\Bookings\Exceptions\CarrierMismatch;
use Commerz\Shipping\Carriers\Exceptions\CarrierRejected;
use Commerz\Shipping\Carriers\Exceptions\InvalidCarrierResult;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Errors\Enums\ShippingErrorCode;
use Commerz\Shipping\Errors\ShippingProblemDefinitions;
use Commerz\Shipping\Methods\Exceptions\MethodArchived;
use Commerz\Shipping\Methods\Exceptions\MethodNotFound;
use Commerz\Shipping\Options\Exceptions\DeliveryUnavailable;
use Commerz\Shipping\Packing\Exceptions\UnpackableItem;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointNotFound;
use Commerz\Shipping\PickupPoints\Exceptions\PickupPointOversized;
use Commerz\Shipping\Rates\Exceptions\InvalidRateConfig;
use Commerz\Shipping\Rates\Exceptions\InvalidRateResult;
use Commerz\Shipping\Zones\Exceptions\ZoneArchived;
use Commerz\Shipping\Zones\Exceptions\ZoneNotFound;
use Packman\Errors\Data\ProblemDefinition;
use Packman\Errors\Enums\ErrorCategory;
use Packman\Errors\Exceptions\CodedException;

it('covers every ShippingErrorCode case with a matching ProblemDefinition', function (): void {
    $definitions = (new ShippingProblemDefinitions)->definitions();
    $codes = array_map(static fn (ProblemDefinition $d): string => $d->code->value(), $definitions);
    $expected = array_column(ShippingErrorCode::cases(), 'value');

    sort($codes);
    sort($expected);

    expect($codes)->toBe($expected);
});

it('exposes Packman error descriptors on shipping exceptions', function (CommerzException $e, ShippingErrorCode $code, ErrorCategory $category): void {
    $descriptor = $e->errorDescriptor();

    expect($e)->toBeInstanceOf(CodedException::class)
        ->and($descriptor->errorCode)->toBe($code)
        ->and($descriptor->category)->toBe($category);
})->with([
    'booking in progress' => [new BookingInProgress('f:1'), ShippingErrorCode::BookingInProgress, ErrorCategory::Conflict],
    'booking inside transaction' => [new BookingInsideTransaction('f:1'), ShippingErrorCode::BookingInsideTransaction, ErrorCategory::Validation],
    'booking not found' => [new BookingNotFound('f:1'), ShippingErrorCode::BookingNotFound, ErrorCategory::NotFound],
    'booking outcome unknown' => [new BookingOutcomeUnknown('f:1', 'manual', new RuntimeException('timeout')), ShippingErrorCode::BookingOutcomeUnknown, ErrorCategory::Unavailable],
    'carrier mismatch' => [new CarrierMismatch('courier', 'manual', 'other'), ShippingErrorCode::CarrierMismatch, ErrorCategory::Validation],
    'carrier rejected' => [new CarrierRejected('manual', 'address_unreachable', 'bad address'), ShippingErrorCode::CarrierRejected, ErrorCategory::Unavailable],
    'invalid carrier result' => [new InvalidCarrierResult('manual', 'bad'), ShippingErrorCode::InvalidCarrierResult, ErrorCategory::Unavailable],
    'duplicate code' => [new DuplicateCode('Shipping method', 'courier'), ShippingErrorCode::DuplicateCode, ErrorCategory::Conflict],
    'method archived' => [new MethodArchived('courier'), ShippingErrorCode::MethodArchived, ErrorCategory::Conflict],
    'method not found' => [new MethodNotFound('m1'), ShippingErrorCode::MethodNotFound, ErrorCategory::NotFound],
    'delivery unavailable' => [new DeliveryUnavailable('courier', 'out_of_zone', 'cannot deliver'), ShippingErrorCode::DeliveryUnavailable, ErrorCategory::Conflict],
    'invalid rate config' => [new InvalidRateConfig('amount', 'must be non-negative'), ShippingErrorCode::InvalidRateConfig, ErrorCategory::Validation],
    'invalid rate result' => [new InvalidRateResult('flat', 'bad'), ShippingErrorCode::InvalidRateResult, ErrorCategory::Unavailable],
    'pickup point not found' => [new PickupPointNotFound('cdek:msk-1'), ShippingErrorCode::PickupPointNotFound, ErrorCategory::NotFound],
    'pickup point oversized' => [new PickupPointOversized('msk-1', 'cdek', 'too big'), ShippingErrorCode::PickupPointOversized, ErrorCategory::Validation],
    'unpackable item' => [new UnpackableItem('sku-1', 'too heavy'), ShippingErrorCode::UnpackableItem, ErrorCategory::Validation],
    'zone archived' => [new ZoneArchived('ru'), ShippingErrorCode::ZoneArchived, ErrorCategory::Conflict],
    'zone not found' => [new ZoneNotFound('z1'), ShippingErrorCode::ZoneNotFound, ErrorCategory::NotFound],
]);

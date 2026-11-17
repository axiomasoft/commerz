<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Data;

use Commerz\Errors\InvalidCommand;
use Commerz\Measures\LocalizedText;
use Commerz\Shipping\Common\Data\ShippingCodes;
use Commerz\Shipping\Methods\Enums\MethodStatus;
use Commerz\Stores\Data\ChannelId;
use Packman\Attributes\Api;

/** A delivery method with its carrier, the channels that offer it and its tariffs. Repeated channels count once. */
#[Api(since: '0.1')]
final readonly class CreateMethod
{
    /**
     * @param  list<ChannelId>  $channels
     * @param  list<ZoneRate>  $rates  at most 500, one per zone and currency
     * @param  MethodStatus  $status  `Active` or `Disabled`
     */
    public function __construct(
        public string $code,
        public LocalizedText $name,
        public string $carrier,
        public array $channels = [],
        public array $rates = [],
        public int $position = 0,
        public MethodStatus $status = MethodStatus::Active,
    ) {
        ShippingCodes::code('code', $code);
        ShippingCodes::carrierKey('carrier', $carrier);
        self::assertName($name);
        self::assertPosition($position);
        ZoneRate::assertValid($rates);

        if ($status === MethodStatus::Archived) {
            throw new InvalidCommand('status', 'a method is created active or disabled', 'shipping.invalid_status');
        }
    }

    /** @internal */
    public static function assertName(LocalizedText $name): void
    {
        foreach ($name->texts as $text) {
            if (mb_strlen($text) > 191) {
                throw new InvalidCommand('name', 'a text is at most 191 characters', 'shipping.invalid_name');
            }
        }
    }

    /** @internal the column is a 32-bit integer */
    public static function assertPosition(int $position): void
    {
        if ($position < -2_147_483_648 || $position > 2_147_483_647) {
            throw new InvalidCommand('position', 'must fit a 32-bit integer', 'shipping.invalid_position');
        }
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Ports\Delivery\Data;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class BookedShipment
{
    /**
     * @param  string|null  $labelRef  where the carrier label can be fetched
     * @param  array<string, scalar>  $meta
     */
    public function __construct(
        public string $carrier,
        public string $tracking,
        public ?string $labelRef = null,
        public array $meta = [],
    ) {}
}

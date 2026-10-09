<?php

declare(strict_types=1);

namespace Commerz\Inventory\Sources\Contracts;

use Commerz\Attributes\Api;
use Commerz\Inventory\Locations\Exceptions\LocationArchived;
use Commerz\Inventory\Locations\Exceptions\LocationNotFound;
use Commerz\Inventory\Sources\Data\AssignSource;
use Commerz\Inventory\Sources\Data\StockSourceView;
use Commerz\Inventory\Sources\Data\UnassignSource;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Exceptions\ChannelNotFound;

/** Which locations a channel sells from, and in which order. */
#[Api(since: '0.1')]
interface StockSources
{
    /** @throws ChannelNotFound|LocationNotFound|LocationArchived */
    public function assign(AssignSource $command): StockSourceView;

    public function unassign(UnassignSource $command): void;

    /**
     * @return list<StockSourceView> by priority, then by location code
     *
     * @throws ChannelNotFound
     */
    public function list(ChannelId $channel): array;
}

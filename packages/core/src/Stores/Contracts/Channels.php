<?php

declare(strict_types=1);

namespace Commerz\Stores\Contracts;

use Commerz\Money\Currency;
use Commerz\Pagination\Page;
use Commerz\Stores\Data\ArchiveChannel;
use Commerz\Stores\Data\ChannelId;
use Commerz\Stores\Data\ChannelView;
use Commerz\Stores\Data\CreateChannel;
use Commerz\Stores\Data\ListChannels;
use Commerz\Stores\Data\UpdateChannel;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;
use Commerz\Stores\Exceptions\DuplicateCode;
use Commerz\Stores\Exceptions\StoreNotFound;
use Packman\Attributes\Api;

#[Api(since: '0.1')]
interface Channels
{
    /**
     * @throws StoreNotFound the store does not exist or is archived
     * @throws DuplicateCode
     */
    public function create(CreateChannel $command): ChannelView;

    /**
     * @throws ChannelNotFound
     * @throws ChannelArchived
     * @throws CurrencyNotAllowed the default currency would fall outside the new currency list
     */
    public function update(UpdateChannel $command): ChannelView;

    /** @throws ChannelNotFound */
    public function archive(ArchiveChannel $command): ChannelView;

    /** @throws ChannelNotFound */
    public function find(ChannelId $id): ChannelView;

    /** @return Page<ChannelView> */
    public function list(ListChannels $query): Page;

    /**
     * Guards new contexts: the channel must exist and be active, and, when given, sell in `$currency`.
     *
     * @throws ChannelNotFound
     * @throws ChannelArchived
     * @throws CurrencyNotAllowed
     */
    public function assertActive(ChannelId $id, ?Currency $currency = null): ChannelView;
}

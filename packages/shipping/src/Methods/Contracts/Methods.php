<?php

declare(strict_types=1);

namespace Commerz\Shipping\Methods\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Shipping\Common\Exceptions\DuplicateCode;
use Commerz\Shipping\Methods\Data\ArchiveMethod;
use Commerz\Shipping\Methods\Data\AssignMethodChannels;
use Commerz\Shipping\Methods\Data\CreateMethod;
use Commerz\Shipping\Methods\Data\DisableMethod;
use Commerz\Shipping\Methods\Data\EnableMethod;
use Commerz\Shipping\Methods\Data\ListMethods;
use Commerz\Shipping\Methods\Data\MethodId;
use Commerz\Shipping\Methods\Data\MethodView;
use Commerz\Shipping\Methods\Data\SetMethodRates;
use Commerz\Shipping\Methods\Data\UnassignMethodChannels;
use Commerz\Shipping\Methods\Data\UpdateMethod;
use Commerz\Shipping\Methods\Exceptions\MethodArchived;
use Commerz\Shipping\Methods\Exceptions\MethodNotFound;
use Commerz\Shipping\Zones\Exceptions\ZoneArchived;
use Commerz\Shipping\Zones\Exceptions\ZoneNotFound;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;

/** Delivery methods: a carrier, the channels that offer the method and its tariffs per zone and currency. */
#[Api(since: '0.1')]
interface Methods
{
    /**
     * @throws DuplicateCode
     * @throws InvalidCommand shipping.unknown_carrier, shipping.unknown_rate, shipping.invalid_rate_config
     * @throws ZoneNotFound|ZoneArchived a tariff of a zone that is missing or archived
     * @throws ChannelNotFound|ChannelArchived
     */
    public function create(CreateMethod $command): MethodView;

    /**
     * @throws MethodNotFound|MethodArchived
     * @throws InvalidCommand shipping.unknown_carrier
     */
    public function update(UpdateMethod $command): MethodView;

    /**
     * Replaces all the tariffs.
     *
     * @throws MethodNotFound|MethodArchived
     * @throws InvalidCommand shipping.unknown_rate, shipping.invalid_rate_config
     * @throws ZoneNotFound|ZoneArchived
     */
    public function setRates(SetMethodRates $command): MethodView;

    /**
     * Adds the channels the method is offered in; the ones it has already change nothing.
     *
     * @throws MethodNotFound|MethodArchived
     * @throws ChannelNotFound|ChannelArchived
     */
    public function assignChannels(AssignMethodChannels $command): MethodView;

    /** Removes channels; the ones the method does not have change nothing. @throws MethodNotFound|MethodArchived */
    public function unassignChannels(UnassignMethodChannels $command): MethodView;

    /** Enabling an active method changes nothing. @throws MethodNotFound|MethodArchived */
    public function enable(EnableMethod $command): MethodView;

    /** Disabling a disabled method changes nothing. @throws MethodNotFound|MethodArchived */
    public function disable(DisableMethod $command): MethodView;

    /** Final; archiving an archived method changes nothing. @throws MethodNotFound */
    public function archive(ArchiveMethod $command): MethodView;

    /** @throws MethodNotFound */
    public function find(MethodId $id): MethodView;

    /** @throws MethodNotFound */
    public function findByCode(string $code): MethodView;

    /** @return Page<MethodView> */
    public function list(ListMethods $query): Page;
}

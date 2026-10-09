<?php

declare(strict_types=1);

namespace Commerz\Promotions\Definitions\Contracts;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Commerz\Promotions\Definitions\Data\ArchivePromotion;
use Commerz\Promotions\Definitions\Data\AssignPromotionChannels;
use Commerz\Promotions\Definitions\Data\CreatePromotion;
use Commerz\Promotions\Definitions\Data\ExplainPromotions;
use Commerz\Promotions\Definitions\Data\ListPromotions;
use Commerz\Promotions\Definitions\Data\PausePromotion;
use Commerz\Promotions\Definitions\Data\PromotionExplanation;
use Commerz\Promotions\Definitions\Data\PromotionId;
use Commerz\Promotions\Definitions\Data\PromotionView;
use Commerz\Promotions\Definitions\Data\ResumePromotion;
use Commerz\Promotions\Definitions\Data\UnassignPromotionChannels;
use Commerz\Promotions\Definitions\Data\UpdatePromotion;
use Commerz\Promotions\Definitions\Exceptions\PromotionArchived;
use Commerz\Promotions\Definitions\Exceptions\PromotionNotFound;
use Commerz\Stores\Exceptions\ChannelArchived;
use Commerz\Stores\Exceptions\ChannelNotFound;
use Commerz\Stores\Exceptions\CurrencyNotAllowed;

/**
 * Promotion definitions: the one way to write them. Every change locks the promotion row, so it waits for the orders
 * being placed with the promotion and the counters of its limits stay exact.
 */
#[Api(since: '0.1')]
interface PromotionDefinitions
{
    /**
     * @throws DuplicateCode
     * @throws InvalidCommand promotions.unknown_activator, promotions.unknown_rule, promotions.rule_not_allowed_in_stage, promotions.invalid_rule_config, promotions.currency_mismatch
     * @throws ChannelNotFound|ChannelArchived
     * @throws CurrencyNotAllowed a channel does not sell in the currency of the promotion
     */
    public function create(CreatePromotion $command): PromotionView;

    /**
     * An update that changes nothing writes nothing and raises no event.
     *
     * @throws PromotionNotFound|PromotionArchived
     * @throws InvalidCommand promotions.unknown_rule, promotions.rule_not_allowed_in_stage, promotions.invalid_rule_config, promotions.currency_mismatch
     */
    public function update(UpdatePromotion $command): PromotionView;

    /** An active promotion is paused; a paused one stays. @throws PromotionNotFound|PromotionArchived */
    public function pause(PausePromotion $command): PromotionView;

    /** A paused promotion is resumed; an active one stays. @throws PromotionNotFound|PromotionArchived */
    public function resume(ResumePromotion $command): PromotionView;

    /** Archiving is final; archiving an archived promotion changes nothing. @throws PromotionNotFound */
    public function archive(ArchivePromotion $command): PromotionView;

    /**
     * Adds channels; one already assigned stays.
     *
     * @throws PromotionNotFound|PromotionArchived
     * @throws ChannelNotFound|ChannelArchived
     * @throws CurrencyNotAllowed
     */
    public function assignChannels(AssignPromotionChannels $command): PromotionView;

    /**
     * Removes channels; one not assigned is ignored.
     *
     * @throws PromotionNotFound|PromotionArchived
     * @throws InvalidCommand promotions.no_channels when no channel would be left
     */
    public function unassignChannels(UnassignPromotionChannels $command): PromotionView;

    /** @throws PromotionNotFound */
    public function find(PromotionId $id): PromotionView;

    /** @throws PromotionNotFound */
    public function findByCode(string $code): PromotionView;

    /** @return Page<PromotionView> */
    public function list(ListPromotions $query): Page;

    /**
     * Runs the quote of the input and tells, for every promotion that was switched on, whether it applied and why not.
     * A failed quote is reported in `failure`, not thrown. Reads only; nothing is spent.
     */
    public function explain(ExplainPromotions $query): PromotionExplanation;
}

<?php

declare(strict_types=1);

namespace Commerz\Promotions\Campaigns\Contracts;

use Commerz\Pagination\Page;
use Commerz\Promotions\Campaigns\Data\ArchiveCampaign;
use Commerz\Promotions\Campaigns\Data\Campaign;
use Commerz\Promotions\Campaigns\Data\CampaignBudget;
use Commerz\Promotions\Campaigns\Data\CampaignId;
use Commerz\Promotions\Campaigns\Data\ConsumeCampaignBudget;
use Commerz\Promotions\Campaigns\Data\CreateCampaign;
use Commerz\Promotions\Campaigns\Data\ListCampaigns;
use Commerz\Promotions\Campaigns\Data\PauseCampaign;
use Commerz\Promotions\Campaigns\Data\RestoreCampaignBudget;
use Commerz\Promotions\Campaigns\Data\ResumeCampaign;
use Commerz\Promotions\Campaigns\Data\UpdateCampaign;
use Commerz\Promotions\Campaigns\Exceptions\CampaignArchived;
use Commerz\Promotions\Campaigns\Exceptions\CampaignBudgetExceeded;
use Commerz\Promotions\Campaigns\Exceptions\CampaignNotFound;
use Commerz\Promotions\Common\Exceptions\DuplicateCode;
use Packman\Attributes\Api;

/**
 * Promotion campaigns and shared campaign budgets (`spend_cap_minor`, `usage_cap`, INV-PRM-001).
 */
#[Api(since: '0.1')]
interface Campaigns
{
    /** @throws DuplicateCode */
    public function create(CreateCampaign $command): Campaign;

    /** @throws CampaignNotFound|CampaignArchived */
    public function update(UpdateCampaign $command): Campaign;

    /** @throws CampaignNotFound|CampaignArchived */
    public function pause(PauseCampaign $command): Campaign;

    /** @throws CampaignNotFound|CampaignArchived */
    public function resume(ResumeCampaign $command): Campaign;

    /** @throws CampaignNotFound */
    public function archive(ArchiveCampaign $command): Campaign;

    /** @throws CampaignNotFound */
    public function find(CampaignId $id): Campaign;

    /** @throws CampaignNotFound */
    public function findByCode(string $code): Campaign;

    /** @return Page<Campaign> */
    public function list(ListCampaigns $query): Page;

    /** @throws CampaignNotFound */
    public function budget(CampaignId $id): CampaignBudget;

    /**
     * Atomically consumes campaign spend/usage caps under `FOR KEY SHARE` + conditional `UPDATE` (INV-PRM-001).
     *
     * @throws CampaignNotFound|CampaignArchived|CampaignBudgetExceeded
     */
    public function consumeBudget(ConsumeCampaignBudget $command): CampaignBudget;

    /**
     * Restores previously consumed campaign spend/usage caps (clamped at zero).
     *
     * @throws CampaignNotFound
     */
    public function restoreBudget(RestoreCampaignBudget $command): CampaignBudget;
}

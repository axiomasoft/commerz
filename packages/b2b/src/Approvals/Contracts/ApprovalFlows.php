<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Contracts;

use Commerz\B2b\Approvals\Data\ApprovalEvaluation;
use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\Approvals\Data\ApprovalFlowView;
use Commerz\B2b\Approvals\Data\ApprovalRuleView;
use Commerz\B2b\Approvals\Data\ApproveFlow;
use Commerz\B2b\Approvals\Data\CreateApprovalRule;
use Commerz\B2b\Approvals\Data\EvaluateApproval;
use Commerz\B2b\Approvals\Data\InitiateApprovalFlow;
use Commerz\B2b\Approvals\Data\RejectFlow;
use Commerz\B2b\Approvals\Exceptions\ApprovalFlowClosed;
use Commerz\B2b\Approvals\Exceptions\ApprovalFlowNotFound;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateNotFound;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateUnauthorized;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitInactive;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Packman\Attributes\Api;

/**
 * Evaluates B2B budget threshold rules & associate spending limits and drives multi-step ApprovalFlow lifecycles.
 */
#[Api(since: '0.1')]
interface ApprovalFlows
{
    /** @throws BusinessUnitNotFound */
    public function createRule(CreateApprovalRule $command): ApprovalRuleView;

    /**
     * Active approval rules applying to `$businessUnitId` (including inherited ancestor rules with
     * `appliesToDescendants = true` when `$includeInherited` is true).
     *
     * @return list<ApprovalRuleView>
     *
     * @throws BusinessUnitNotFound
     */
    public function rulesFor(BusinessUnitId $businessUnitId, bool $includeInherited = true): array;

    /**
     * Evaluates whether `$command->buyer` requires approval to place `$command->amount` under `$command->businessUnitId`.
     *
     * @throws BusinessUnitNotFound|BusinessUnitInactive
     * @throws AssociateNotFound|AssociateUnauthorized
     */
    public function evaluate(EvaluateApproval $command): ApprovalEvaluation;

    /**
     * Opens an `ApprovalFlow` (`Pending` when approval is required, or immediately `Approved` when under all thresholds).
     *
     * @throws BusinessUnitNotFound|BusinessUnitInactive
     * @throws AssociateNotFound|AssociateUnauthorized
     */
    public function initiate(InitiateApprovalFlow $command): ApprovalFlowView;

    /**
     * Records an approver's approval decision and marks the flow `Approved` once `requiredApprovals` is reached.
     *
     * @throws ApprovalFlowNotFound|ApprovalFlowClosed
     * @throws AssociateNotFound|AssociateUnauthorized
     */
    public function approve(ApproveFlow $command): ApprovalFlowView;

    /**
     * Rejects a pending approval flow.
     *
     * @throws ApprovalFlowNotFound|ApprovalFlowClosed
     * @throws AssociateNotFound|AssociateUnauthorized
     */
    public function reject(RejectFlow $command): ApprovalFlowView;

    public function find(ApprovalFlowId $id): ?ApprovalFlowView;

    /** @throws ApprovalFlowNotFound */
    public function get(ApprovalFlowId $id): ApprovalFlowView;

    public function findLatestForSubject(string $subjectRef): ?ApprovalFlowView;
}

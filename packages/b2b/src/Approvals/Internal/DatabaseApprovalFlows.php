<?php

declare(strict_types=1);

namespace Commerz\B2b\Approvals\Internal;

use Commerz\B2b\Approvals\Contracts\ApprovalFlows;
use Commerz\B2b\Approvals\Data\ApprovalDecisionView;
use Commerz\B2b\Approvals\Data\ApprovalEvaluation;
use Commerz\B2b\Approvals\Data\ApprovalFlowId;
use Commerz\B2b\Approvals\Data\ApprovalFlowView;
use Commerz\B2b\Approvals\Data\ApprovalRuleId;
use Commerz\B2b\Approvals\Data\ApprovalRuleView;
use Commerz\B2b\Approvals\Data\ApproveFlow;
use Commerz\B2b\Approvals\Data\CreateApprovalRule;
use Commerz\B2b\Approvals\Data\EvaluateApproval;
use Commerz\B2b\Approvals\Data\InitiateApprovalFlow;
use Commerz\B2b\Approvals\Data\RejectFlow;
use Commerz\B2b\Approvals\Enums\ApprovalStatus;
use Commerz\B2b\Approvals\Exceptions\ApprovalFlowClosed;
use Commerz\B2b\Approvals\Exceptions\ApprovalFlowNotFound;
use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\B2b\BusinessUnits\Enums\BusinessUnitStatus;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateNotFound;
use Commerz\B2b\BusinessUnits\Exceptions\AssociateUnauthorized;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitInactive;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Internal\B2bCodec;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\CustomerRef;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Outbox\Contracts\Outbox;
use Commerz\Outbox\Data\IntegrationEvent;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseApprovalFlows implements ApprovalFlows
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerz,
        private B2bOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private Outbox $outbox,
        private BusinessUnits $businessUnits,
        private Clock $clock,
    ) {}

    #[Override]
    public function createRule(CreateApprovalRule $command): ApprovalRuleView
    {
        return $this->transactions->run(function () use ($command): ApprovalRuleView {
            $unit = $this->businessUnits->get($command->businessUnitId);
            $id = ApprovalRuleId::generate();
            $now = PgTimestamp::format($this->clock->now());

            $this->db()->table($this->tables->table('b2b_approval_rules'))->insert([
                'id' => $id->value,
                'business_unit_id' => $unit->id->value,
                'name' => $command->name,
                'currency' => $command->thresholdAmount->currency->code,
                'threshold_minor' => $command->thresholdAmount->amountMinor,
                'approver_role' => $command->approverRole->value,
                'required_approvals' => $command->requiredApprovals,
                'applies_to_descendants' => $command->appliesToDescendants,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $this->db()
                ->table($this->tables->table('b2b_approval_rules'))
                ->where('id', $id->value)
                ->first();

            return self::hydrateRule($row ?? throw new ApprovalFlowNotFound($id->value));
        });
    }

    #[Override]
    public function rulesFor(BusinessUnitId $businessUnitId, bool $includeInherited = true): array
    {
        $unit = $this->businessUnits->get($businessUnitId);
        $ancestorIds = $includeInherited
            ? array_map(static fn (BusinessUnitId $aid): string => $aid->value, $unit->ancestorIds())
            : [];
        $allIds = [...$ancestorIds, $unit->id->value];

        $rows = $this->db()
            ->table($this->tables->table('b2b_approval_rules'))
            ->whereIn('business_unit_id', $allIds)
            ->where('active', true)
            ->orderBy('threshold_minor')
            ->get()
            ->all();

        $rules = [];
        foreach ($rows as $row) {
            $rule = self::hydrateRule($row);
            if (! $rule->businessUnitId->equals($unit->id) && ! $rule->appliesToDescendants) {
                continue;
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    #[Override]
    public function evaluate(EvaluateApproval $command): ApprovalEvaluation
    {
        $unit = $this->businessUnits->get($command->businessUnitId);

        if ($unit->status !== BusinessUnitStatus::Active) {
            throw new BusinessUnitInactive($unit->id, $unit->status);
        }

        $associate = $this->businessUnits->resolveAssociate($unit->id, $command->buyer)
            ?? throw new AssociateNotFound($unit->id, $command->buyer);

        if (! $associate->role->canPurchase()) {
            throw new AssociateUnauthorized($unit->id, $command->buyer, "role '{$associate->role->value}' cannot submit orders or quotes");
        }

        $reasons = [];
        $matchedRules = [];
        $requiredRole = AssociateRole::Approver;
        $requiredApprovals = 1;

        if ($associate->perOrderSpendingLimit !== null
            && $associate->perOrderSpendingLimit->currency->equals($command->amount->currency)
            && $associate->exceedsPerOrderLimit($command->amount)) {
            $reasons[] = 'exceeds_associate_spending_limit';
        }

        foreach ($this->rulesFor($unit->id, includeInherited: true) as $rule) {
            if (! $rule->thresholdAmount->currency->equals($command->amount->currency)) {
                continue;
            }

            if ($command->amount->compare($rule->thresholdAmount) > 0) {
                $reasons[] = 'exceeds_budget_threshold:'.$rule->name;
                $matchedRules[] = $rule->id;

                if ($rule->approverRole === AssociateRole::Admin) {
                    $requiredRole = AssociateRole::Admin;
                }

                if ($rule->requiredApprovals > $requiredApprovals) {
                    $requiredApprovals = $rule->requiredApprovals;
                }
            }
        }

        return new ApprovalEvaluation(
            requiresApproval: $reasons !== [],
            reasons: array_values(array_unique($reasons)),
            requiredRole: $requiredRole,
            requiredApprovals: $requiredApprovals,
            matchedRules: $matchedRules,
        );
    }

    #[Override]
    public function initiate(InitiateApprovalFlow $command): ApprovalFlowView
    {
        return $this->transactions->run(function () use ($command): ApprovalFlowView {
            $evaluation = $this->evaluate(new EvaluateApproval(
                businessUnitId: $command->businessUnitId,
                buyer: $command->requestedBy,
                amount: $command->amount,
            ));

            $id = ApprovalFlowId::generate();
            $instant = $this->clock->now();
            $now = PgTimestamp::format($instant);
            $status = $evaluation->requiresApproval ? ApprovalStatus::Pending : ApprovalStatus::Approved;
            $resolvedAt = $evaluation->requiresApproval ? null : $now;

            $this->db()->table($this->tables->table('b2b_approval_flows'))->insert([
                'id' => $id->value,
                'business_unit_id' => $command->businessUnitId->value,
                'requested_by_ref' => (string) $command->requestedBy,
                'subject_ref' => $command->subjectRef,
                'currency' => $command->amount->currency->code,
                'amount_minor' => $command->amount->amountMinor,
                'status' => $status->value,
                'required_role' => $evaluation->requiredRole->value,
                'required_approvals' => $evaluation->requiredApprovals,
                'reasons' => B2bCodec::encodeStrings($evaluation->reasons),
                'decisions' => B2bCodec::encodeJson([]),
                'resolved_at' => $resolvedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->outbox->record(IntegrationEvent::of(
                type: $evaluation->requiresApproval ? 'b2b.approval_requested' : 'b2b.approval_auto_approved',
                aggregateRef: 'approval_flow:'.$id->value,
                payload: [
                    'approval_flow_id' => $id->value,
                    'business_unit_id' => $command->businessUnitId->value,
                    'requested_by_ref' => (string) $command->requestedBy,
                    'subject_ref' => $command->subjectRef,
                    'amount_minor' => $command->amount->amountMinor,
                    'currency' => $command->amount->currency->code,
                    'status' => $status->value,
                ],
                occurredAt: $instant,
            ));

            return $this->get($id);
        });
    }

    #[Override]
    public function approve(ApproveFlow $command): ApprovalFlowView
    {
        return $this->transactions->run(function () use ($command): ApprovalFlowView {
            $table = $this->tables->table('b2b_approval_flows');
            $row = $this->db()
                ->table($table)
                ->where('id', $command->approvalFlowId->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ApprovalFlowNotFound($command->approvalFlowId->value);
            }

            $flow = self::hydrateFlow($row);

            if ($flow->status !== ApprovalStatus::Pending) {
                throw new ApprovalFlowClosed($flow->id, $flow->status);
            }

            if (! $this->options->allowSelfApproval && $flow->requestedBy->equals($command->approver)) {
                throw new AssociateUnauthorized($flow->businessUnitId, $command->approver, 'requester cannot self-approve their own flow');
            }

            $associate = $this->businessUnits->resolveAssociate($flow->businessUnitId, $command->approver)
                ?? throw new AssociateNotFound($flow->businessUnitId, $command->approver);

            if (! $associate->role->satisfiesApproverRole($flow->requiredRole)) {
                throw new AssociateUnauthorized(
                    $flow->businessUnitId,
                    $command->approver,
                    "role '{$associate->role->value}' does not satisfy required approver role '{$flow->requiredRole->value}'",
                );
            }

            if ($associate->perOrderSpendingLimit !== null
                && $associate->perOrderSpendingLimit->currency->equals($flow->amount->currency)
                && $associate->exceedsPerOrderLimit($flow->amount)) {
                throw new AssociateUnauthorized(
                    $flow->businessUnitId,
                    $command->approver,
                    'flow amount exceeds approver spending limit',
                );
            }

            foreach ($flow->decisions as $existingDecision) {
                if ($existingDecision->approver->equals($command->approver)) {
                    throw new AssociateUnauthorized($flow->businessUnitId, $command->approver, 'approver has already recorded a decision');
                }
            }

            $instant = $this->clock->now();
            $now = PgTimestamp::format($instant);
            $newDecision = new ApprovalDecisionView(
                approver: $command->approver,
                role: $associate->role,
                decision: ApprovalStatus::Approved,
                comment: $command->comment,
                decidedAt: $instant,
            );
            $decisions = [...$flow->decisions, $newDecision];

            $approvedCount = count(array_filter(
                $decisions,
                static fn (ApprovalDecisionView $d): bool => $d->decision === ApprovalStatus::Approved,
            ));

            $nextStatus = $approvedCount >= $flow->requiredApprovals
                ? ApprovalStatus::Approved
                : ApprovalStatus::Pending;
            $resolvedAt = $nextStatus === ApprovalStatus::Approved ? $now : null;

            $this->db()
                ->table($table)
                ->where('id', $flow->id->value)
                ->update([
                    'status' => $nextStatus->value,
                    'decisions' => self::encodeDecisions($decisions),
                    'resolved_at' => $resolvedAt,
                    'updated_at' => $now,
                ]);

            if ($nextStatus === ApprovalStatus::Approved) {
                $this->outbox->record(IntegrationEvent::of(
                    type: 'b2b.approval_approved',
                    aggregateRef: 'approval_flow:'.$flow->id->value,
                    payload: [
                        'approval_flow_id' => $flow->id->value,
                        'business_unit_id' => $flow->businessUnitId->value,
                        'subject_ref' => $flow->subjectRef,
                        'approver_ref' => (string) $command->approver,
                    ],
                    occurredAt: $instant,
                ));
            }

            return $this->get($flow->id);
        });
    }

    #[Override]
    public function reject(RejectFlow $command): ApprovalFlowView
    {
        return $this->transactions->run(function () use ($command): ApprovalFlowView {
            $table = $this->tables->table('b2b_approval_flows');
            $row = $this->db()
                ->table($table)
                ->where('id', $command->approvalFlowId->value)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new ApprovalFlowNotFound($command->approvalFlowId->value);
            }

            $flow = self::hydrateFlow($row);

            if ($flow->status !== ApprovalStatus::Pending) {
                throw new ApprovalFlowClosed($flow->id, $flow->status);
            }

            $associate = $this->businessUnits->resolveAssociate($flow->businessUnitId, $command->approver)
                ?? throw new AssociateNotFound($flow->businessUnitId, $command->approver);

            if (! $associate->role->canApprove()) {
                throw new AssociateUnauthorized($flow->businessUnitId, $command->approver, "role '{$associate->role->value}' cannot reject approval flows");
            }

            $instant = $this->clock->now();
            $now = PgTimestamp::format($instant);
            $decisions = [
                ...$flow->decisions,
                new ApprovalDecisionView(
                    approver: $command->approver,
                    role: $associate->role,
                    decision: ApprovalStatus::Rejected,
                    comment: $command->comment,
                    decidedAt: $instant,
                ),
            ];

            $this->db()
                ->table($table)
                ->where('id', $flow->id->value)
                ->update([
                    'status' => ApprovalStatus::Rejected->value,
                    'decisions' => self::encodeDecisions($decisions),
                    'resolved_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->outbox->record(IntegrationEvent::of(
                type: 'b2b.approval_rejected',
                aggregateRef: 'approval_flow:'.$flow->id->value,
                payload: [
                    'approval_flow_id' => $flow->id->value,
                    'business_unit_id' => $flow->businessUnitId->value,
                    'subject_ref' => $flow->subjectRef,
                    'approver_ref' => (string) $command->approver,
                    'comment' => $command->comment,
                ],
                occurredAt: $instant,
            ));

            return $this->get($flow->id);
        });
    }

    #[Override]
    public function find(ApprovalFlowId $id): ?ApprovalFlowView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_approval_flows'))
            ->where('id', $id->value)
            ->first();

        return $row === null ? null : self::hydrateFlow($row);
    }

    #[Override]
    public function get(ApprovalFlowId $id): ApprovalFlowView
    {
        return $this->find($id) ?? throw new ApprovalFlowNotFound($id->value);
    }

    #[Override]
    public function findLatestForSubject(string $subjectRef): ?ApprovalFlowView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_approval_flows'))
            ->where('subject_ref', trim($subjectRef))
            ->orderByDesc('created_at')
            ->first();

        return $row === null ? null : self::hydrateFlow($row);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerz->connection);
    }

    private static function hydrateRule(object $row): ApprovalRuleView
    {
        return new ApprovalRuleView(
            id: ApprovalRuleId::fromString(Rows::string($row, 'id')),
            businessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            name: Rows::string($row, 'name'),
            thresholdAmount: Money::ofMinor(Rows::int($row, 'threshold_minor'), Rows::string($row, 'currency')),
            approverRole: AssociateRole::from(Rows::string($row, 'approver_role')),
            requiredApprovals: Rows::int($row, 'required_approvals'),
            appliesToDescendants: (bool) (get_object_vars($row)['applies_to_descendants'] ?? false),
            active: (bool) (get_object_vars($row)['active'] ?? false),
            createdAt: B2bCodec::parseDate(Rows::string($row, 'created_at')),
        );
    }

    private static function hydrateFlow(object $row): ApprovalFlowView
    {
        $decisionsRaw = B2bCodec::decodeJson(Rows::string($row, 'decisions'));
        $decisions = [];

        foreach ($decisionsRaw as $item) {
            if (! is_array($item)) {
                continue;
            }
            $approver = isset($item['approver_ref']) && is_string($item['approver_ref']) ? $item['approver_ref'] : null;
            $role = isset($item['role']) && is_string($item['role']) ? $item['role'] : null;
            $decision = isset($item['decision']) && is_string($item['decision']) ? $item['decision'] : null;
            $decidedAt = isset($item['decided_at']) && is_string($item['decided_at']) ? $item['decided_at'] : null;
            $comment = isset($item['comment']) && is_string($item['comment']) ? $item['comment'] : null;

            if ($approver !== null && $role !== null && $decision !== null && $decidedAt !== null) {
                $decisions[] = new ApprovalDecisionView(
                    approver: CustomerRef::fromString($approver),
                    role: AssociateRole::from($role),
                    decision: ApprovalStatus::from($decision),
                    comment: $comment,
                    decidedAt: B2bCodec::parseDate($decidedAt),
                );
            }
        }

        return new ApprovalFlowView(
            id: ApprovalFlowId::fromString(Rows::string($row, 'id')),
            businessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            requestedBy: CustomerRef::fromString(Rows::string($row, 'requested_by_ref')),
            subjectRef: Rows::string($row, 'subject_ref'),
            amount: Money::ofMinor(Rows::int($row, 'amount_minor'), Rows::string($row, 'currency')),
            status: ApprovalStatus::from(Rows::string($row, 'status')),
            requiredRole: AssociateRole::from(Rows::string($row, 'required_role')),
            requiredApprovals: Rows::int($row, 'required_approvals'),
            reasons: B2bCodec::decodeStrings(Rows::string($row, 'reasons')),
            decisions: $decisions,
            createdAt: B2bCodec::parseDate(Rows::string($row, 'created_at')),
            resolvedAt: B2bCodec::parseNullableDate(Rows::nullableString($row, 'resolved_at')),
        );
    }

    /** @param list<ApprovalDecisionView> $decisions */
    private static function encodeDecisions(array $decisions): string
    {
        return B2bCodec::encodeJson(array_map(
            static fn (ApprovalDecisionView $d): array => [
                'approver_ref' => (string) $d->approver,
                'role' => $d->role->value,
                'decision' => $d->decision->value,
                'comment' => $d->comment,
                'decided_at' => PgTimestamp::format($d->decidedAt),
            ],
            $decisions,
        ));
    }
}

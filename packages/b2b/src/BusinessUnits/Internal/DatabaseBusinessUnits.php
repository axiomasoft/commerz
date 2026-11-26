<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Internal;

use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Data\AssignAssociate;
use Commerz\B2b\BusinessUnits\Data\AssociateView;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitView;
use Commerz\B2b\BusinessUnits\Data\CreateBusinessUnit;
use Commerz\B2b\BusinessUnits\Data\RemoveAssociate;
use Commerz\B2b\BusinessUnits\Data\UpdateBusinessUnit;
use Commerz\B2b\BusinessUnits\Enums\AssociateRole;
use Commerz\B2b\BusinessUnits\Enums\BusinessUnitStatus;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Commerz\B2b\BusinessUnits\Exceptions\DuplicateBusinessUnitCode;
use Commerz\B2b\BusinessUnits\Exceptions\HierarchyDepthExceeded;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Internal\B2bCodec;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\CustomerRef;
use Commerz\Identity\Uuid7;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseBusinessUnits implements BusinessUnits
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $commerz,
        private B2bOptions $options,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    #[Override]
    public function create(CreateBusinessUnit $command): BusinessUnitView
    {
        return $this->transactions->run(function () use ($command): BusinessUnitView {
            $id = BusinessUnitId::generate();
            $parent = $command->parentId === null ? null : $this->get($command->parentId);

            $depth = $parent === null ? 0 : $parent->depth + 1;

            if ($depth > $this->options->maxHierarchyDepth) {
                throw new HierarchyDepthExceeded($this->options->maxHierarchyDepth, $depth);
            }

            $rootId = $parent instanceof BusinessUnitView ? $parent->rootId : $id;
            $path = $parent === null
                ? '/'.$id->value.'/'
                : $parent->materializedPath.$id->value.'/';
            $now = PgTimestamp::format($this->clock->now());

            try {
                $this->db()->table($this->tables->table('b2b_business_units'))->insert([
                    'id' => $id->value,
                    'code' => $command->code,
                    'name' => $command->name,
                    'parent_id' => $parent?->id->value,
                    'root_id' => $rootId->value,
                    'materialized_path' => $path,
                    'depth' => $depth,
                    'status' => BusinessUnitStatus::Active->value,
                    'default_currency' => $command->defaultCurrency->code,
                    'tax_id' => $command->taxId,
                    'default_payment_terms_code' => $command->defaultPaymentTermsCode,
                    'billing_address' => B2bCodec::encodeAddress($command->billingAddress),
                    'shipping_address' => B2bCodec::encodeAddress($command->shippingAddress),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateBusinessUnitCode($command->code);
            }

            return $this->get($id);
        });
    }

    #[Override]
    public function update(UpdateBusinessUnit $command): BusinessUnitView
    {
        return $this->transactions->run(function () use ($command): BusinessUnitView {
            $existing = $this->get($command->id);
            $updates = [
                'updated_at' => PgTimestamp::format($this->clock->now()),
            ];

            if ($command->name !== null) {
                $updates['name'] = $command->name;
            }

            if ($command->status !== null) {
                $updates['status'] = $command->status->value;
            }

            if ($command->taxId !== null) {
                $updates['tax_id'] = $command->taxId;
            }

            if ($command->defaultPaymentTermsCode !== null) {
                $updates['default_payment_terms_code'] = $command->defaultPaymentTermsCode;
            }

            if ($command->billingAddress !== null) {
                $updates['billing_address'] = B2bCodec::encodeAddress($command->billingAddress);
            }

            if ($command->shippingAddress !== null) {
                $updates['shipping_address'] = B2bCodec::encodeAddress($command->shippingAddress);
            }

            $this->db()
                ->table($this->tables->table('b2b_business_units'))
                ->where('id', $existing->id->value)
                ->update($updates);

            return $this->get($existing->id);
        });
    }

    #[Override]
    public function find(BusinessUnitId $id): ?BusinessUnitView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_business_units'))
            ->where('id', $id->value)
            ->first();

        return $row === null ? null : self::hydrateUnit($row);
    }

    #[Override]
    public function get(BusinessUnitId $id): BusinessUnitView
    {
        return $this->find($id) ?? throw new BusinessUnitNotFound($id->value);
    }

    #[Override]
    public function findByCode(string $code): ?BusinessUnitView
    {
        $row = $this->db()
            ->table($this->tables->table('b2b_business_units'))
            ->where('code', strtolower(trim($code)))
            ->first();

        return $row === null ? null : self::hydrateUnit($row);
    }

    #[Override]
    public function ancestors(BusinessUnitId $id): array
    {
        $unit = $this->get($id);
        $ancestorIds = $unit->ancestorIds();

        if ($ancestorIds === []) {
            return [];
        }

        $idStrings = array_map(static fn (BusinessUnitId $aid): string => $aid->value, $ancestorIds);
        $rows = $this->db()
            ->table($this->tables->table('b2b_business_units'))
            ->whereIn('id', $idStrings)
            ->orderBy('depth')
            ->get()
            ->all();

        return array_values(array_map(self::hydrateUnit(...), $rows));
    }

    #[Override]
    public function children(BusinessUnitId $parentId): array
    {
        $rows = $this->db()
            ->table($this->tables->table('b2b_business_units'))
            ->where('parent_id', $parentId->value)
            ->orderBy('code')
            ->get()
            ->all();

        return array_values(array_map(self::hydrateUnit(...), $rows));
    }

    #[Override]
    public function descendants(BusinessUnitId $id): array
    {
        $unit = $this->get($id);
        $rows = $this->db()
            ->table($this->tables->table('b2b_business_units'))
            ->where('materialized_path', 'like', $unit->materializedPath.'%')
            ->where('id', '!=', $unit->id->value)
            ->orderBy('depth')
            ->orderBy('code')
            ->get()
            ->all();

        return array_values(array_map(self::hydrateUnit(...), $rows));
    }

    #[Override]
    public function assignAssociate(AssignAssociate $command): AssociateView
    {
        return $this->transactions->run(function () use ($command): AssociateView {
            $unit = $this->get($command->businessUnitId);
            $customerRef = (string) $command->customer;
            $currency = $command->perOrderSpendingLimit?->currency->code ?? $command->periodSpendingLimit?->currency->code;
            $now = PgTimestamp::format($this->clock->now());
            $table = $this->tables->table('b2b_associates');

            $existing = $this->db()
                ->table($table)
                ->where('business_unit_id', $unit->id->value)
                ->where('customer_ref', $customerRef)
                ->first();

            if ($existing !== null) {
                $this->db()
                    ->table($table)
                    ->where('id', Rows::string($existing, 'id'))
                    ->update([
                        'role' => $command->role->value,
                        'spending_currency' => $currency,
                        'per_order_limit_minor' => $command->perOrderSpendingLimit?->amountMinor,
                        'period_limit_minor' => $command->periodSpendingLimit?->amountMinor,
                        'updated_at' => $now,
                    ]);
            } else {
                $this->db()->table($table)->insert([
                    'id' => Uuid7::generate(),
                    'business_unit_id' => $unit->id->value,
                    'customer_ref' => $customerRef,
                    'role' => $command->role->value,
                    'spending_currency' => $currency,
                    'per_order_limit_minor' => $command->perOrderSpendingLimit?->amountMinor,
                    'period_limit_minor' => $command->periodSpendingLimit?->amountMinor,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $row = $this->db()
                ->table($table)
                ->where('business_unit_id', $unit->id->value)
                ->where('customer_ref', $customerRef)
                ->first();

            return self::hydrateAssociate($row ?? throw new BusinessUnitNotFound($unit->id->value), $unit->id, false);
        });
    }

    #[Override]
    public function removeAssociate(RemoveAssociate $command): void
    {
        $this->db()
            ->table($this->tables->table('b2b_associates'))
            ->where('business_unit_id', $command->businessUnitId->value)
            ->where('customer_ref', (string) $command->customer)
            ->delete();
    }

    #[Override]
    public function resolveAssociate(BusinessUnitId $id, CustomerRef $customer, bool $includeInherited = true): ?AssociateView
    {
        $unit = $this->get($id);
        $chainIds = $includeInherited
            ? array_reverse([...array_map(static fn (BusinessUnitId $aid): string => $aid->value, $unit->ancestorIds()), $unit->id->value])
            : [$unit->id->value];

        $rows = $this->db()
            ->table($this->tables->table('b2b_associates'))
            ->whereIn('business_unit_id', $chainIds)
            ->where('customer_ref', (string) $customer)
            ->get()
            ->all();

        $byUnit = [];
        foreach ($rows as $row) {
            $byUnit[Rows::string($row, 'business_unit_id')] = $row;
        }

        foreach ($chainIds as $candidateUnitId) {
            if (isset($byUnit[$candidateUnitId])) {
                return self::hydrateAssociate($byUnit[$candidateUnitId], $unit->id, $candidateUnitId !== $unit->id->value);
            }
        }

        return null;
    }

    #[Override]
    public function associates(BusinessUnitId $id, bool $includeInherited = true): array
    {
        $unit = $this->get($id);
        $chainIds = $includeInherited
            ? array_reverse([...array_map(static fn (BusinessUnitId $aid): string => $aid->value, $unit->ancestorIds()), $unit->id->value])
            : [$unit->id->value];

        $rows = $this->db()
            ->table($this->tables->table('b2b_associates'))
            ->whereIn('business_unit_id', $chainIds)
            ->orderBy('customer_ref')
            ->get()
            ->all();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[Rows::string($row, 'business_unit_id')][] = $row;
        }

        $resolved = [];
        $seen = [];

        foreach ($chainIds as $candidateUnitId) {
            foreach ($grouped[$candidateUnitId] ?? [] as $row) {
                $customerRef = Rows::string($row, 'customer_ref');
                if (isset($seen[$customerRef])) {
                    continue;
                }
                $seen[$customerRef] = true;
                $resolved[] = self::hydrateAssociate($row, $unit->id, $candidateUnitId !== $unit->id->value);
            }
        }

        return $resolved;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->commerz->connection);
    }

    private static function hydrateUnit(object $row): BusinessUnitView
    {
        $parentId = Rows::nullableString($row, 'parent_id');

        return new BusinessUnitView(
            id: BusinessUnitId::fromString(Rows::string($row, 'id')),
            code: Rows::string($row, 'code'),
            name: Rows::string($row, 'name'),
            parentId: $parentId === null ? null : BusinessUnitId::fromString($parentId),
            rootId: BusinessUnitId::fromString(Rows::string($row, 'root_id')),
            materializedPath: Rows::string($row, 'materialized_path'),
            depth: Rows::int($row, 'depth'),
            status: BusinessUnitStatus::from(Rows::string($row, 'status')),
            defaultCurrency: Currency::of(Rows::string($row, 'default_currency')),
            taxId: Rows::nullableString($row, 'tax_id'),
            defaultPaymentTermsCode: Rows::nullableString($row, 'default_payment_terms_code'),
            billingAddress: B2bCodec::decodeAddress(Rows::nullableString($row, 'billing_address')),
            shippingAddress: B2bCodec::decodeAddress(Rows::nullableString($row, 'shipping_address')),
            createdAt: B2bCodec::parseDate(Rows::string($row, 'created_at')),
            updatedAt: B2bCodec::parseDate(Rows::string($row, 'updated_at')),
        );
    }

    private static function hydrateAssociate(object $row, BusinessUnitId $targetUnitId, bool $inherited): AssociateView
    {
        $spendingCurrency = Rows::nullableString($row, 'spending_currency');
        $perOrderMinor = Rows::nullableInt($row, 'per_order_limit_minor');
        $periodMinor = Rows::nullableInt($row, 'period_limit_minor');

        return new AssociateView(
            businessUnitId: $targetUnitId,
            definedOnBusinessUnitId: BusinessUnitId::fromString(Rows::string($row, 'business_unit_id')),
            customer: CustomerRef::fromString(Rows::string($row, 'customer_ref')),
            role: AssociateRole::from(Rows::string($row, 'role')),
            perOrderSpendingLimit: $perOrderMinor !== null && $spendingCurrency !== null
                ? Money::ofMinor($perOrderMinor, $spendingCurrency)
                : null,
            periodSpendingLimit: $periodMinor !== null && $spendingCurrency !== null
                ? Money::ofMinor($periodMinor, $spendingCurrency)
                : null,
            inherited: $inherited,
            assignedAt: B2bCodec::parseDate(Rows::string($row, 'created_at')),
        );
    }
}

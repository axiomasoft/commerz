<?php

declare(strict_types=1);

namespace Commerz\B2b\BusinessUnits\Contracts;

use Commerz\B2b\BusinessUnits\Data\AssignAssociate;
use Commerz\B2b\BusinessUnits\Data\AssociateView;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitId;
use Commerz\B2b\BusinessUnits\Data\BusinessUnitView;
use Commerz\B2b\BusinessUnits\Data\CreateBusinessUnit;
use Commerz\B2b\BusinessUnits\Data\RemoveAssociate;
use Commerz\B2b\BusinessUnits\Data\UpdateBusinessUnit;
use Commerz\B2b\BusinessUnits\Exceptions\BusinessUnitNotFound;
use Commerz\B2b\BusinessUnits\Exceptions\DuplicateBusinessUnitCode;
use Commerz\B2b\BusinessUnits\Exceptions\HierarchyDepthExceeded;
use Commerz\Identity\CustomerRef;
use Packman\Attributes\Api;

/**
 * Manages B2B BusinessUnit hierarchies (root companies, divisions, departments) and Associate role/spending policies.
 */
#[Api(since: '0.1')]
interface BusinessUnits
{
    /**
     * @throws BusinessUnitNotFound when `$command->parentId` does not exist
     * @throws DuplicateBusinessUnitCode
     * @throws HierarchyDepthExceeded
     */
    public function create(CreateBusinessUnit $command): BusinessUnitView;

    /** @throws BusinessUnitNotFound */
    public function update(UpdateBusinessUnit $command): BusinessUnitView;

    public function find(BusinessUnitId $id): ?BusinessUnitView;

    /** @throws BusinessUnitNotFound */
    public function get(BusinessUnitId $id): BusinessUnitView;

    public function findByCode(string $code): ?BusinessUnitView;

    /**
     * Ancestor business units ordered from root down to immediate parent (excluding `$id`).
     *
     * @return list<BusinessUnitView>
     *
     * @throws BusinessUnitNotFound
     */
    public function ancestors(BusinessUnitId $id): array;

    /**
     * Direct child business units of `$parentId`.
     *
     * @return list<BusinessUnitView>
     */
    public function children(BusinessUnitId $parentId): array;

    /**
     * All descendants under `$id` ordered by materialized path.
     *
     * @return list<BusinessUnitView>
     *
     * @throws BusinessUnitNotFound
     */
    public function descendants(BusinessUnitId $id): array;

    /** @throws BusinessUnitNotFound */
    public function assignAssociate(AssignAssociate $command): AssociateView;

    public function removeAssociate(RemoveAssociate $command): void;

    /**
     * Resolves an associate's effective membership on `$id`, walking up the ancestor chain from nearest parent to root
     * when `$includeInherited` is true.
     *
     * @throws BusinessUnitNotFound
     */
    public function resolveAssociate(BusinessUnitId $id, CustomerRef $customer, bool $includeInherited = true): ?AssociateView;

    /**
     * Lists associates on `$id` (and inherited associates from ancestors when `$includeInherited` is true, with local
     * assignments taking precedence over inherited ones).
     *
     * @return list<AssociateView>
     *
     * @throws BusinessUnitNotFound
     */
    public function associates(BusinessUnitId $id, bool $includeInherited = true): array;
}

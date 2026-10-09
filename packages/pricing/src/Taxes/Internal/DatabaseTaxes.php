<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Internal;

use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\Uuid7;
use Commerz\Money\Rate;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Pricing\Common\Exceptions\DuplicateCode;
use Commerz\Pricing\Common\Internal\Clock;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Pricing\Common\Internal\Windows;
use Commerz\Pricing\Taxes\Contracts\Taxes;
use Commerz\Pricing\Taxes\Data\DefineTaxClass;
use Commerz\Pricing\Taxes\Data\DefineTaxZone;
use Commerz\Pricing\Taxes\Data\ListTaxRates;
use Commerz\Pricing\Taxes\Data\RemoveTaxRate;
use Commerz\Pricing\Taxes\Data\RescheduleTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxRate;
use Commerz\Pricing\Taxes\Data\SetTaxZoneMembers;
use Commerz\Pricing\Taxes\Data\TaxClassId;
use Commerz\Pricing\Taxes\Data\TaxClassView;
use Commerz\Pricing\Taxes\Data\TaxRateId;
use Commerz\Pricing\Taxes\Data\TaxRateView;
use Commerz\Pricing\Taxes\Data\TaxZoneMember;
use Commerz\Pricing\Taxes\Data\TaxZoneView;
use Commerz\Pricing\Taxes\Events\TaxRatesChanged;
use Commerz\Pricing\Taxes\Exceptions\TaxClassNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateNotFound;
use Commerz\Pricing\Taxes\Exceptions\TaxRateOverlap;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneMemberTaken;
use Commerz\Pricing\Taxes\Exceptions\TaxZoneNotFound;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Lock order: the membership advisory lock before any zone or member row when defining a zone or replacing members;
 * a rate is written under the transaction advisory lock of its key (class, zone, tax code), then its own row
 * `for no key update`. Membership changes are serialized: sorted inserts alone cannot prevent reciprocal claims from
 * deadlocking on uncommitted member deletions. Like prices, a rate is an upsert by hand:
 * PostgreSQL cannot `ON CONFLICT DO UPDATE` through an exclusion constraint (TAX-01).
 */
final readonly class DatabaseTaxes implements Taxes
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private TaxZoneLookup $zones,
    ) {}

    public function defineClass(DefineTaxClass $command): TaxClassView
    {
        return $this->transactions->run(function () use ($command): TaxClassView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('tax_classes'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Tax class', $command->code);
            }

            return new TaxClassView(new TaxClassId($id), $command->code, $command->name);
        });
    }

    public function defineZone(DefineTaxZone $command): TaxZoneView
    {
        return $this->transactions->run(function () use ($command): TaxZoneView {
            $this->lockMembers();
            $db = $this->db();
            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                $db->transaction(fn () => $db->table($this->tables->table('tax_zones'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => $command->name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Tax zone', $command->code);
            }

            $this->insertMembers($id, $command->members);

            return $this->zoneView($id);
        });
    }

    public function setZoneMembers(SetTaxZoneMembers $command): TaxZoneView
    {
        return $this->transactions->run(function () use ($command): TaxZoneView {
            $this->lockMembers();
            $row = $this->zones->byCode($command->zone, 'for no key update') ?? throw new TaxZoneNotFound($command->zone);
            $id = Rows::string($row, 'id');

            $this->db()->table($this->tables->table('tax_zone_members'))->where('tax_zone_id', $id)->delete();
            $this->insertMembers($id, $command->members);

            return $this->zoneView($id);
        });
    }

    public function setRate(SetTaxRate $command): TaxRateView
    {
        return $this->transactions->run(function () use ($command): TaxRateView {
            $class = $this->classId($command->taxClass);
            $zone = Rows::string($this->zones->byCode($command->zone) ?? throw new TaxZoneNotFound($command->zone), 'id');

            Windows::lock($this->db(), self::lockKey($class, $zone, $command->code));

            $id = $this->exact($class, $zone, $command);

            $id ??= $this->insertRate($class, $zone, $command)
                // The insert collided with an identical command: the one that waited sees the committed row of the other.
                ?? $this->exact($class, $zone, $command)
                ?? throw new TaxRateOverlap(
                    $command->taxClass,
                    $command->zone,
                    $command->code,
                    $this->conflicting($class, $zone, $command->code, $command->validFrom, $command->validUntil, null),
                );

            $this->db()->table($this->tables->table('tax_rates'))->where('id', $id)->update([
                'rate_bp' => $command->rateBp,
                'updated_at' => Clock::now(),
            ]);
            $this->events->dispatch(new TaxRatesChanged($command->taxClass, $command->zone));

            return $this->rateView($id);
        });
    }

    public function rescheduleRate(RescheduleTaxRate $command): TaxRateView
    {
        return $this->transactions->run(function () use ($command): TaxRateView {
            $db = $this->db();
            $table = $this->tables->table('tax_rates');
            $id = $command->rate->value;
            $row = $db->table($table)->where('id', $id)->first() ?? throw TaxRateNotFound::forId($id);
            $class = Rows::string($row, 'tax_class_id');
            $zone = Rows::string($row, 'tax_zone_id');
            $code = Rows::string($row, 'code');

            // The key, then the row, as in `setRate`.
            Windows::lock($db, self::lockKey($class, $zone, $code));
            $db->table($table)->where('id', $id)->lock('for no key update')->first() ?? throw TaxRateNotFound::forId($id);

            try {
                $db->transaction(fn () => $db->table($table)->where('id', $id)->update([
                    'valid_from' => Windows::format($command->validFrom),
                    'valid_until' => Windows::format($command->validUntil),
                    'updated_at' => Clock::now(),
                ]));
            } catch (QueryException $e) {
                if (! Windows::isExclusionViolation($e)) {
                    throw $e;
                }

                [$classCode, $zoneCode] = $this->codesOf($class, $zone);

                throw new TaxRateOverlap(
                    $classCode,
                    $zoneCode,
                    $code,
                    $this->conflicting($class, $zone, $code, $command->validFrom, $command->validUntil, $id),
                );
            }

            [$classCode, $zoneCode] = $this->codesOf($class, $zone);
            $this->events->dispatch(new TaxRatesChanged($classCode, $zoneCode));

            return $this->rateView($id);
        });
    }

    public function removeRate(RemoveTaxRate $command): void
    {
        $this->transactions->run(function () use ($command): void {
            $db = $this->db();
            $table = $this->tables->table('tax_rates');
            $row = $db->table($table)->where('id', $command->rate->value)->lock('for no key update')->first();

            if ($row === null) {
                return;
            }

            $db->table($table)->where('id', $command->rate->value)->delete();
            [$class, $zone] = $this->codesOf(Rows::string($row, 'tax_class_id'), Rows::string($row, 'tax_zone_id'));
            $this->events->dispatch(new TaxRatesChanged($class, $zone));
        });
    }

    public function listClasses(): array
    {
        return array_values(array_map(
            static fn (object $row): TaxClassView => new TaxClassView(
                new TaxClassId(Rows::string($row, 'id')),
                Rows::string($row, 'code'),
                Rows::string($row, 'name'),
            ),
            $this->db()->table($this->tables->table('tax_classes'))->orderBy('code')->get()->all(),
        ));
    }

    public function listZones(): array
    {
        return $this->zones->views(array_values($this->db()->table($this->tables->table('tax_zones'))->orderBy('code')->get()->all()));
    }

    public function listRates(ListTaxRates $query): array
    {
        $builder = $this->rates()
            ->orderBy('c.code')
            ->orderBy('z.code')
            ->orderBy('r.code')
            ->orderByRaw('r.valid_from asc nulls first')
            ->orderBy('r.id');

        if ($query->zone !== null) {
            $builder->where('r.tax_zone_id', Rows::string($this->zones->byCode($query->zone) ?? throw new TaxZoneNotFound($query->zone), 'id'));
        }

        if ($query->taxClass !== null) {
            $builder->where('r.tax_class_id', $this->classId($query->taxClass));
        }

        if ($query->at instanceof DateTimeImmutable) {
            TaxRateLookup::containing($builder, $query->at);
        }

        return array_values(array_map(self::rateViewOf(...), $builder->get()->all()));
    }

    public function zoneFor(Address $address): ?TaxZoneView
    {
        $zone = $this->zones->forAddress($address);

        return $zone === null ? null : $this->zones->views([$zone])[0];
    }

    /** @param  list<TaxZoneMember>  $members sorted */
    private function insertMembers(string $zone, array $members): void
    {
        if ($members === []) {
            return;
        }

        $db = $this->db();

        try {
            $db->transaction(fn () => $db->table($this->tables->table('tax_zone_members'))->insert(array_map(
                static fn (TaxZoneMember $member): array => [
                    'tax_zone_id' => $zone,
                    'country' => $member->country,
                    'region' => $member->region,
                ],
                $members,
            )));
        } catch (UniqueConstraintViolationException $e) {
            foreach ($members as $member) {
                $owner = $this->zoneOf($member);

                if ($owner !== null) {
                    throw new TaxZoneMemberTaken($member->country, $member->region, $owner);
                }
            }

            throw $e;
        }
    }

    /** @return string|null code of the zone the pair belongs to */
    private function zoneOf(TaxZoneMember $member): ?string
    {
        $code = $this->db()->table($this->tables->table('tax_zone_members').' as m')
            ->join($this->tables->table('tax_zones').' as z', 'z.id', '=', 'm.tax_zone_id')
            ->where('m.country', $member->country)
            ->whereRaw('m.region is not distinct from ?', [$member->region])
            ->value('z.code');

        return is_string($code) ? $code : null;
    }

    /** @return string|null id of the inserted rate; null when the window overlaps an existing one */
    private function insertRate(string $class, string $zone, SetTaxRate $command): ?string
    {
        $db = $this->db();
        $id = Uuid7::generate();
        $now = Clock::now();

        try {
            // A savepoint: the violation must not poison the transaction of the caller.
            $db->transaction(fn () => $db->table($this->tables->table('tax_rates'))->insert([
                'id' => $id,
                'tax_class_id' => $class,
                'tax_zone_id' => $zone,
                'code' => $command->code,
                'rate_bp' => $command->rateBp,
                'valid_from' => Windows::format($command->validFrom),
                'valid_until' => Windows::format($command->validUntil),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        } catch (QueryException $e) {
            if (! Windows::isExclusionViolation($e)) {
                throw $e;
            }

            return null;
        }

        return $id;
    }

    /** The rate of the same key and window, locked. */
    private function exact(string $class, string $zone, SetTaxRate $command): ?string
    {
        $id = $this->db()->table($this->tables->table('tax_rates'))
            ->where('tax_class_id', $class)
            ->where('tax_zone_id', $zone)
            ->where('code', $command->code)
            ->whereRaw('valid_from is not distinct from ?::timestamptz', [Windows::format($command->validFrom)])
            ->whereRaw('valid_until is not distinct from ?::timestamptz', [Windows::format($command->validUntil)])
            ->lock('for no key update')
            ->value('id');

        return is_string($id) ? $id : null;
    }

    /** Another rate of the same key whose window meets `[from, until)`, the lowest id first. */
    private function conflicting(string $class, string $zone, string $code, ?DateTimeImmutable $from, ?DateTimeImmutable $until, ?string $exceptRate): ?string
    {
        $builder = $this->db()->table($this->tables->table('tax_rates'))
            ->where('tax_class_id', $class)
            ->where('tax_zone_id', $zone)
            ->where('code', $code)
            ->whereRaw("tstzrange(valid_from, valid_until, '[)') && tstzrange(?::timestamptz, ?::timestamptz, '[)')", [Windows::format($from), Windows::format($until)])
            ->orderBy('id');

        if ($exceptRate !== null) {
            $builder->where('id', '<>', $exceptRate);
        }

        $id = $builder->value('id');

        return is_string($id) ? $id : null;
    }

    private function classId(string $code): string
    {
        $id = $this->db()->table($this->tables->table('tax_classes'))->where('code', $code)->value('id');

        return is_string($id) ? $id : throw new TaxClassNotFound($code);
    }

    /** @return array{string, string} codes of the tax class and of the tax zone */
    private function codesOf(string $class, string $zone): array
    {
        $classCode = $this->db()->table($this->tables->table('tax_classes'))->where('id', $class)->value('code');
        $zoneCode = $this->db()->table($this->tables->table('tax_zones'))->where('id', $zone)->value('code');

        return [is_string($classCode) ? $classCode : '', is_string($zoneCode) ? $zoneCode : ''];
    }

    private function zoneView(string $id): TaxZoneView
    {
        $row = $this->db()->table($this->tables->table('tax_zones'))->where('id', $id)->first();

        return $this->zones->views([$row ?? throw new TaxZoneNotFound($id)])[0];
    }

    private function rateView(string $id): TaxRateView
    {
        $row = $this->rates()->where('r.id', $id)->first();

        return self::rateViewOf($row ?? throw TaxRateNotFound::forId($id));
    }

    /** Rates with the codes of their class and zone. */
    private function rates(): Builder
    {
        return $this->db()->table($this->tables->table('tax_rates').' as r')
            ->join($this->tables->table('tax_classes').' as c', 'c.id', '=', 'r.tax_class_id')
            ->join($this->tables->table('tax_zones').' as z', 'z.id', '=', 'r.tax_zone_id')
            ->select('r.id', 'r.code', 'r.rate_bp', 'r.valid_from', 'r.valid_until', 'c.code as class_code', 'z.code as zone_code');
    }

    private static function rateViewOf(object $row): TaxRateView
    {
        $from = Rows::nullableString($row, 'valid_from');
        $until = Rows::nullableString($row, 'valid_until');

        return new TaxRateView(
            new TaxRateId(Rows::string($row, 'id')),
            Rows::string($row, 'class_code'),
            Rows::string($row, 'zone_code'),
            Rows::string($row, 'code'),
            Rate::bp(Rows::int($row, 'rate_bp')),
            $from === null ? null : new DateTimeImmutable($from),
            $until === null ? null : new DateTimeImmutable($until),
        );
    }

    private static function lockKey(string $class, string $zone, string $code): string
    {
        return "commerz.tax_rate:{$class}|{$zone}|{$code}";
    }

    private function lockMembers(): void
    {
        Windows::lock($this->db(), 'commerz.tax_zone_members:'.$this->tables->table('tax_zone_members'));
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

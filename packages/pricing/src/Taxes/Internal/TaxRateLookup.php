<?php

declare(strict_types=1);

namespace Commerz\Pricing\Taxes\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Money\Rate;
use Commerz\Pricing\Common\Internal\Rows;
use Commerz\Pricing\Common\Internal\Windows;
use Commerz\Schema\TableNames;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;

/** Reads the rates in force. The one reader of the validity windows: `listRates(at)` and the `rates` calculator share it. */
#[Internal]
final readonly class TaxRateLookup
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
    ) {}

    /**
     * Rates of the classes in a zone at a moment, one query.
     *
     * @param  list<string>  $classes  tax class codes
     * @return array<string, list<array{code: string, rate: Rate}>> by class code, each sorted by tax code
     */
    public function inForce(string $zoneId, array $classes, DateTimeImmutable $at): array
    {
        $builder = $this->db()->table($this->tables->table('tax_rates').' as r')
            ->join($this->tables->table('tax_classes').' as c', 'c.id', '=', 'r.tax_class_id')
            ->where('r.tax_zone_id', $zoneId)
            ->whereIn('c.code', $classes)
            ->orderBy('c.code')
            ->orderBy('r.code')
            ->select('c.code as class_code', 'r.code', 'r.rate_bp');

        $byClass = [];

        foreach (self::containing($builder, $at)->get() as $row) {
            $byClass[Rows::string($row, 'class_code')][] = [
                'code' => Rows::string($row, 'code'),
                'rate' => Rate::bp(Rows::int($row, 'rate_bp')),
            ];
        }

        return $byClass;
    }

    /** Keeps the rates of the builder (aliased `r`) whose window `[from, until)` holds the moment. */
    public static function containing(Builder $builder, DateTimeImmutable $at): Builder
    {
        $moment = Windows::format($at);

        return $builder->whereRaw('(r.valid_from is null or r.valid_from <= ?::timestamptz)', [$moment])
            ->whereRaw('(r.valid_until is null or ?::timestamptz < r.valid_until)', [$moment]);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

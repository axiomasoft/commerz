<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Packman\Attributes\Internal;

/** Turns option and value codes into the ids of a product's axes. */
#[Internal]
final readonly class AxisResolver
{
    public function __construct(private TableNames $tables) {}

    /** @return list<Axis> the product's axes by position */
    public function axes(ConnectionInterface $db, string $product): array
    {
        return $this->axesForMany($db, [$product])[$product] ?? [];
    }

    /**
     * Loads the axes of many products in 2 SQL queries (zero N+1 for batch variant upserts).
     *
     * @param  list<string>  $productIds
     * @return array<string, list<Axis>> product id => axes by position
     */
    public function axesForMany(ConnectionInterface $db, array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return [];
        }

        $rows = [];

        foreach (array_chunk($productIds, 500) as $chunk) {
            $chunkRows = $db->table($this->tables->table('product_options').' as p')
                ->join($this->tables->table('options').' as o', 'o.id', '=', 'p.option_id')
                ->whereIn('p.product_id', $chunk)
                ->orderBy('p.product_id')
                ->orderBy('p.position')
                ->select('p.product_id', 'o.id', 'o.code')
                ->get()
                ->all();

            array_push($rows, ...array_values(array_filter($chunkRows, is_object(...))));
        }

        $result = array_fill_keys($productIds, []);

        if ($rows === []) {
            return $result;
        }

        $optionIds = array_values(array_unique(array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows)));
        $values = [];

        foreach (array_chunk($optionIds, 500) as $chunk) {
            $valueRows = $db->table($this->tables->table('option_values'))
                ->whereIn('option_id', $chunk)
                ->select('id', 'option_id', 'code')
                ->get();

            foreach ($valueRows as $value) {
                $values[Rows::string($value, 'option_id')][Rows::string($value, 'code')] = Rows::string($value, 'id');
            }
        }

        foreach ($rows as $row) {
            $optionId = Rows::string($row, 'id');
            $result[Rows::string($row, 'product_id')][] = new Axis(
                $optionId,
                Rows::string($row, 'code'),
                $values[$optionId] ?? [],
            );
        }

        return $result;
    }

    /**
     * @param  list<Axis>  $axes
     * @param  list<string>  $codes  option codes named by a command
     */
    public static function assertSameAxes(array $axes, array $codes): void
    {
        $expected = array_map(static fn (Axis $axis): string => $axis->code, $axes);
        $missing = array_values(array_diff($expected, $codes));
        $extra = array_values(array_diff($codes, $expected));

        if ($missing !== []) {
            throw new InvalidAxes("axis '{$missing[0]}' of the product is missing");
        }

        if ($extra !== []) {
            throw new InvalidAxes("the product has no axis '{$extra[0]}'");
        }
    }

    /**
     * @param  list<Axis>  $axes
     * @param  array<string, string>  $combination  option code => value code, exactly the product's axes
     * @return array<string, string> option id => option value id, in axis order
     */
    public static function resolve(array $axes, array $combination): array
    {
        self::assertSameAxes($axes, array_map(strval(...), array_keys($combination)));
        $ids = [];

        foreach ($axes as $axis) {
            $ids[$axis->option] = self::value($axis, $combination[$axis->code]);
        }

        return $ids;
    }

    public static function value(Axis $axis, string $code): string
    {
        return $axis->values[$code] ?? throw new InvalidAxes("axis '{$axis->code}' has no value '{$code}'");
    }

    /**
     * @param  list<Axis>  $axes
     * @param  array<string, string>  $combination
     * @return array<string, string> the combination in axis order
     */
    public static function ordered(array $axes, array $combination): array
    {
        $ordered = [];

        foreach ($axes as $axis) {
            $ordered[$axis->code] = $combination[$axis->code];
        }

        return $ordered;
    }
}

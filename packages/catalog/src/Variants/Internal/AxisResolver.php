<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Internal;

use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;

/** Turns option and value codes into the ids of a product's axes. */
final readonly class AxisResolver
{
    public function __construct(private TableNames $tables) {}

    /** @return list<Axis> the product's axes by position */
    public function axes(ConnectionInterface $db, string $product): array
    {
        $rows = $db->table($this->tables->table('product_options').' as p')
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'p.option_id')
            ->where('p.product_id', $product)
            ->orderBy('p.position')
            ->select('o.id', 'o.code')
            ->get()
            ->all();

        if ($rows === []) {
            return [];
        }

        $values = [];

        $valueRows = $db->table($this->tables->table('option_values'))
            ->whereIn('option_id', array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows))
            ->select('id', 'option_id', 'code')
            ->get();

        foreach ($valueRows as $value) {
            $values[Rows::string($value, 'option_id')][Rows::string($value, 'code')] = Rows::string($value, 'id');
        }

        return array_values(array_map(static fn (object $row): Axis => new Axis(
            Rows::string($row, 'id'),
            Rows::string($row, 'code'),
            $values[Rows::string($row, 'id')] ?? [],
        ), $rows));
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

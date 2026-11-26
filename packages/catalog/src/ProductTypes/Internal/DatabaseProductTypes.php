<?php

declare(strict_types=1);

namespace Commerz\Catalog\ProductTypes\Internal;

use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Catalog\ProductTypes\Contracts\ProductTypes;
use Commerz\Catalog\ProductTypes\Data\ArchiveProductType;
use Commerz\Catalog\ProductTypes\Data\AttributeSlotView;
use Commerz\Catalog\ProductTypes\Data\CreateProductType;
use Commerz\Catalog\ProductTypes\Data\ListProductTypes;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Data\ProductTypeView;
use Commerz\Catalog\ProductTypes\Data\UpdateProductType;
use Commerz\Catalog\ProductTypes\Enums\AttributeLevel;
use Commerz\Catalog\ProductTypes\Enums\ProductTypeStatus;
use Commerz\Catalog\ProductTypes\Exceptions\InvalidProductType;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;
use Packman\Plugins\Contracts\Contributions\Contributions;

#[Internal]
final readonly class DatabaseProductTypes implements ProductTypes
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Contributions $contributions,
    ) {}

    public function create(CreateProductType $command): ProductTypeView
    {
        $this->contributions->get(ProductKind::KIND, $command->kind, ProductKind::class);

        return $this->transactions->run(function () use ($command): ProductTypeView {
            $db = $this->db();
            $attributes = $this->attributes($db, [
                ...$command->productAttributes,
                ...$command->variantAttributes,
                ...$command->overridableVariantAttributes,
            ]);
            $options = $this->options($db, $command->variantAxes);
            $id = Uuid7::generate();
            $now = PgTimestamp::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('product_types'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => Documents::encode($command->name->texts),
                    'kind' => $command->kind,
                    'status' => ProductTypeStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Product type', $command->code);
            }

            $this->insertSlots($db, $id, AttributeLevel::Product, $command->productAttributes, $attributes, $command->required, 0);
            $this->insertSlots($db, $id, AttributeLevel::OverridableVariant, $command->overridableVariantAttributes, $attributes, $command->required, 0);
            $this->insertSlots($db, $id, AttributeLevel::Variant, $command->variantAttributes, $attributes, $command->required, 0);

            $axes = [];

            foreach ($command->variantAxes as $position => $code) {
                $axes[] = ['product_type_id' => $id, 'option_id' => $options[$code], 'position' => $position];
            }

            if ($axes !== []) {
                $db->table($this->tables->table('product_type_options'))->insert($axes);
            }

            return $this->find(new ProductTypeId($id));
        });
    }

    public function update(UpdateProductType $command): ProductTypeView
    {
        return $this->transactions->run(function () use ($command): ProductTypeView {
            $db = $this->db();
            $row = $this->lock($command->type);

            if (Rows::string($row, 'status') === ProductTypeStatus::Archived->value) {
                throw new ProductTypeArchived(Rows::string($row, 'code'));
            }

            $adds = [
                ...$command->addProductAttributes,
                ...$command->addVariantAttributes,
                ...$command->addOverridableVariantAttributes,
            ];

            if ($command->name === null && $adds === []) {
                return $this->find($command->type);
            }

            if ($adds !== []) {
                $attributes = $this->attributes($db, $adds);
                $slots = $this->tables->table('product_type_attributes');

                foreach ($attributes as $code => $attribute) {
                    if ($db->table($slots)->where('product_type_id', $command->type->value)->where('attribute_id', $attribute)->exists()) {
                        throw new InvalidProductType("attribute '{$code}' already has a slot");
                    }
                }

                foreach ([
                    [AttributeLevel::Product, $command->addProductAttributes],
                    [AttributeLevel::OverridableVariant, $command->addOverridableVariantAttributes],
                    [AttributeLevel::Variant, $command->addVariantAttributes],
                ] as [$level, $codes]) {
                    $last = $db->table($slots)->where('product_type_id', $command->type->value)->where('level', $level->value)->max('position');
                    $this->insertSlots($db, $command->type->value, $level, $codes, $attributes, [], is_numeric($last) ? (int) $last + 1 : 0);
                }
            }

            $changes = ['updated_at' => PgTimestamp::now()];

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            $db->table($this->tables->table('product_types'))->where('id', $command->type->value)->update($changes);

            return $this->find($command->type);
        });
    }

    public function archive(ArchiveProductType $command): ProductTypeView
    {
        return $this->transactions->run(function () use ($command): ProductTypeView {
            $row = $this->lock($command->type);

            if (Rows::string($row, 'status') !== ProductTypeStatus::Archived->value) {
                $this->db()->table($this->tables->table('product_types'))
                    ->where('id', $command->type->value)
                    ->update(['status' => ProductTypeStatus::Archived->value, 'updated_at' => PgTimestamp::now()]);
            }

            return $this->find($command->type);
        });
    }

    public function find(ProductTypeId $id): ProductTypeView
    {
        $row = $this->db()->table($this->tables->table('product_types'))->where('id', $id->value)->first();

        if ($row === null) {
            throw new ProductTypeNotFound($id->value);
        }

        return $this->views([$row])[0];
    }

    public function findByCode(string $code): ProductTypeView
    {
        $row = $this->db()->table($this->tables->table('product_types'))->where('code', $code)->first();

        if ($row === null) {
            throw new ProductTypeNotFound($code);
        }

        return $this->views([$row])[0];
    }

    public function list(ListProductTypes $query): Page
    {
        $builder = $this->db()->table($this->tables->table('product_types'))->orderBy('code')->limit($query->first + 1);

        if (! $query->includeArchived) {
            $builder->where('status', ProductTypeStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function lock(ProductTypeId $id): object
    {
        $row = $this->db()->table($this->tables->table('product_types'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new ProductTypeNotFound($id->value);
    }

    /**
     * Existing, non-retired attributes by code, read under `for share` in id order.
     *
     * @param  list<string>  $codes
     * @return array<string, string> code => id
     */
    private function attributes(ConnectionInterface $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $rows = $db->table($this->tables->table('attributes'))->whereIn('code', $codes)->orderBy('id')->lock('for share')->get();
        $found = [];

        foreach ($rows as $row) {
            $code = Rows::string($row, 'code');

            if (Rows::string($row, 'status') === AttributeStatus::Retired->value) {
                throw new InvalidProductType("attribute '{$code}' is retired");
            }

            $found[$code] = Rows::string($row, 'id');
        }

        foreach ($codes as $code) {
            if (! isset($found[$code])) {
                throw new AttributeNotFound($code);
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string> code => id
     */
    private function options(ConnectionInterface $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $found = $db->table($this->tables->table('options'))->whereIn('code', $codes)->orderBy('id')->lock('for share')->pluck('id', 'code')->all();

        foreach ($codes as $code) {
            if (! isset($found[$code]) || ! is_string($found[$code])) {
                throw new OptionNotFound($code);
            }
        }

        /** @var array<string, string> $found */
        return $found;
    }

    /**
     * @param  list<string>  $codes
     * @param  array<string, string>  $ids  code => attribute id
     * @param  list<string>  $required
     */
    private function insertSlots(ConnectionInterface $db, string $type, AttributeLevel $level, array $codes, array $ids, array $required, int $position): void
    {
        $rows = [];

        foreach ($codes as $code) {
            $rows[] = [
                'product_type_id' => $type,
                'attribute_id' => $ids[$code],
                'level' => $level->value,
                'required' => in_array($code, $required, true),
                'position' => $position++,
            ];
        }

        if ($rows !== []) {
            $db->table($this->tables->table('product_type_attributes'))->insert($rows);
        }
    }

    /**
     * @param  list<object>  $rows
     * @return list<ProductTypeView>
     */
    private function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $db = $this->db();
        $ids = array_map(static fn (object $row): string => Rows::string($row, 'id'), $rows);
        $slots = [];
        $axes = [];

        $slotRows = $db->table($this->tables->table('product_type_attributes').' as s')
            ->join($this->tables->table('attributes').' as a', 'a.id', '=', 's.attribute_id')
            ->whereIn('s.product_type_id', $ids)
            ->orderByRaw("case s.level when 'product' then 0 when 'overridable_variant' then 1 else 2 end")
            ->orderBy('s.position')
            ->select('s.product_type_id', 's.attribute_id', 's.level', 's.required', 's.position', 'a.code')
            ->get();

        foreach ($slotRows as $slot) {
            $slots[Rows::string($slot, 'product_type_id')][] = new AttributeSlotView(
                new AttributeId(Rows::string($slot, 'attribute_id')),
                Rows::string($slot, 'code'),
                AttributeLevel::from(Rows::string($slot, 'level')),
                Rows::bool($slot, 'required'),
                Rows::int($slot, 'position'),
            );
        }

        $axisRows = $db->table($this->tables->table('product_type_options').' as p')
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'p.option_id')
            ->whereIn('p.product_type_id', $ids)
            ->orderBy('p.position')
            ->select('p.product_type_id', 'o.code')
            ->get();

        foreach ($axisRows as $axis) {
            $axes[Rows::string($axis, 'product_type_id')][] = Rows::string($axis, 'code');
        }

        return array_map(static fn (object $row): ProductTypeView => new ProductTypeView(
            new ProductTypeId(Rows::string($row, 'id')),
            Rows::string($row, 'code'),
            Rows::localized($row, 'name'),
            Rows::string($row, 'kind'),
            ProductTypeStatus::from(Rows::string($row, 'status')),
            $slots[Rows::string($row, 'id')] ?? [],
            $axes[Rows::string($row, 'id')] ?? [],
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        ), $rows);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

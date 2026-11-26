<?php

declare(strict_types=1);

namespace Commerz\Catalog\Relations\Internal;

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\Relations\Contracts\ProductRelations;
use Commerz\Catalog\Relations\Data\AddProductRelation;
use Commerz\Catalog\Relations\Data\ListProductRelations;
use Commerz\Catalog\Relations\Data\ProductRelationId;
use Commerz\Catalog\Relations\Data\ProductRelationView;
use Commerz\Catalog\Relations\Data\RemoveProductRelation;
use Commerz\Catalog\Relations\Data\ReplaceProductRelations;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Commerz\Catalog\Relations\Exceptions\ProductRelationNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseProductRelations implements ProductRelations
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    public function add(AddProductRelation $command): ProductRelationView
    {
        return $this->transactions->run(function () use ($command): ProductRelationView {
            $db = $this->db();
            $this->lockActiveProducts($db, [$command->sourceProduct->value, $command->targetProduct->value]);

            $table = $this->tables->table('product_relations');
            $now = PgTimestamp::now($this->clock);

            $existing = $db->table($table)
                ->where('source_product_id', $command->sourceProduct->value)
                ->where('target_product_id', $command->targetProduct->value)
                ->where('relation_type', $command->type->value)
                ->first();

            if ($existing !== null) {
                $id = Rows::string($existing, 'id');
                $db->table($table)->where('id', $id)->update([
                    'position' => $command->position,
                    'updated_at' => $now,
                ]);
            } else {
                $id = Uuid7::generate();
                $db->table($table)->insert([
                    'id' => $id,
                    'source_product_id' => $command->sourceProduct->value,
                    'target_product_id' => $command->targetProduct->value,
                    'relation_type' => $command->type->value,
                    'position' => $command->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $row = $db->table($table)->where('id', $id)->first();
            assert($row !== null);

            return self::view($row);
        });
    }

    public function remove(RemoveProductRelation $command): void
    {
        $deleted = $this->db()->table($this->tables->table('product_relations'))
            ->where('source_product_id', $command->sourceProduct->value)
            ->where('target_product_id', $command->targetProduct->value)
            ->where('relation_type', $command->type->value)
            ->delete();

        if ($deleted === 0) {
            throw new ProductRelationNotFound("{$command->sourceProduct->value}:{$command->type->value}:{$command->targetProduct->value}");
        }
    }

    public function replace(ReplaceProductRelations $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $db = $this->db();
            $productIds = array_merge(
                [$command->sourceProduct->value],
                array_map(static fn (ProductId $id): string => $id->value, $command->targets),
            );
            $this->lockActiveProducts($db, $productIds);

            $table = $this->tables->table('product_relations');
            $db->table($table)
                ->where('source_product_id', $command->sourceProduct->value)
                ->where('relation_type', $command->type->value)
                ->delete();

            $now = PgTimestamp::now($this->clock);

            if ($command->targets !== []) {
                $db->table($table)->insert(array_map(static fn (ProductId $target, int $position): array => [
                    'id' => Uuid7::generate(),
                    'source_product_id' => $command->sourceProduct->value,
                    'target_product_id' => $target->value,
                    'relation_type' => $command->type->value,
                    'position' => $position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $command->targets, array_keys($command->targets)));
            }

            return $this->ofProduct(new ListProductRelations($command->sourceProduct, $command->type));
        });
    }

    public function ofProduct(ListProductRelations $query): array
    {
        $db = $this->db();
        $this->assertProductExists($db, $query->sourceProduct);

        $builder = $db->table($this->tables->table('product_relations'))
            ->where('source_product_id', $query->sourceProduct->value)
            ->orderBy('position')
            ->orderBy('id');

        if ($query->type instanceof ProductRelationType) {
            $builder->where('relation_type', $query->type->value);
        }

        return array_values(array_map(self::view(...), $builder->get()->all()));
    }

    public function inverseOf(ProductId $targetProduct, ?ProductRelationType $type = null): array
    {
        $db = $this->db();
        $this->assertProductExists($db, $targetProduct);

        $builder = $db->table($this->tables->table('product_relations'))
            ->where('target_product_id', $targetProduct->value)
            ->orderBy('position')
            ->orderBy('id');

        if ($type instanceof ProductRelationType) {
            $builder->where('relation_type', $type->value);
        }

        return array_values(array_map(self::view(...), $builder->get()->all()));
    }

    /** @param  list<string>  $productIds */
    private function lockActiveProducts(ConnectionInterface $db, array $productIds): void
    {
        $unique = array_values(array_unique($productIds));
        sort($unique);

        $rows = $db->table($this->tables->table('products'))
            ->whereIn('id', $unique)
            ->orderBy('id')
            ->lock('for share')
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($unique as $id) {
            $row = $rows[$id] ?? throw new ProductNotFound($id);

            if (Rows::string($row, 'status') === ProductStatus::Archived->value) {
                throw new ProductArchived(Rows::string($row, 'handle'));
            }
        }
    }

    private function assertProductExists(ConnectionInterface $db, ProductId $product): void
    {
        if (! $db->table($this->tables->table('products'))->where('id', $product->value)->exists()) {
            throw new ProductNotFound($product->value);
        }
    }

    private static function view(object $row): ProductRelationView
    {
        return new ProductRelationView(
            id: new ProductRelationId(Rows::string($row, 'id')),
            sourceProduct: new ProductId(Rows::string($row, 'source_product_id')),
            targetProduct: new ProductId(Rows::string($row, 'target_product_id')),
            type: ProductRelationType::from(Rows::string($row, 'relation_type')),
            position: Rows::int($row, 'position'),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->tables->connection() ?? $this->config->connection);
    }
}

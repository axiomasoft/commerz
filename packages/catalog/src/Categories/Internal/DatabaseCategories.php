<?php

declare(strict_types=1);

namespace Commerz\Catalog\Categories\Internal;

use Commerz\Catalog\Categories\Contracts\Categories;
use Commerz\Catalog\Categories\Data\ArchiveCategory;
use Commerz\Catalog\Categories\Data\AssignProductCategories;
use Commerz\Catalog\Categories\Data\CategoryId;
use Commerz\Catalog\Categories\Data\CategoryView;
use Commerz\Catalog\Categories\Data\CreateCategory;
use Commerz\Catalog\Categories\Data\ListCategories;
use Commerz\Catalog\Categories\Data\MoveCategory;
use Commerz\Catalog\Categories\Data\UpdateCategory;
use Commerz\Catalog\Categories\Enums\CategoryStatus;
use Commerz\Catalog\Categories\Exceptions\CategoryArchived;
use Commerz\Catalog\Categories\Exceptions\CategoryCycle;
use Commerz\Catalog\Categories\Exceptions\CategoryHasChildren;
use Commerz\Catalog\Categories\Exceptions\CategoryNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Common\Internal\Locks;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The tree stays acyclic because every move runs under one advisory lock of the whole tree and checks for a cycle
 * after taking it. A parent is held `for share` while a child is created under it or moved under it, so archiving
 * the parent (`for no key update`) cannot slip in between the check and the commit.
 */
final readonly class DatabaseCategories implements Categories
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
    ) {}

    public function create(CreateCategory $command): CategoryView
    {
        return $this->transactions->run(function () use ($command): CategoryView {
            $db = $this->db();

            if ($command->parent instanceof CategoryId) {
                $this->lockActive($command->parent, 'for share');
            }

            $id = Uuid7::generate();
            $now = Clock::now();

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('categories'))->insert([
                    'id' => $id,
                    'parent_id' => $command->parent?->value,
                    'code' => $command->code,
                    'name' => Documents::encode($command->name->texts),
                    'position' => $command->position,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Category', $command->code);
            }

            return $this->find(new CategoryId($id));
        });
    }

    public function update(UpdateCategory $command): CategoryView
    {
        return $this->transactions->run(function () use ($command): CategoryView {
            $row = $this->lockActive($command->category, 'for no key update');
            $changes = [];

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            if ($command->position !== null && $command->position !== Rows::int($row, 'position')) {
                $changes['position'] = $command->position;
            }

            if ($changes !== []) {
                $this->db()->table($this->tables->table('categories'))
                    ->where('id', $command->category->value)
                    ->update($changes + ['updated_at' => Clock::now()]);
            }

            return $this->find($command->category);
        });
    }

    public function move(MoveCategory $command): CategoryView
    {
        return $this->transactions->run(function () use ($command): CategoryView {
            $db = $this->db();
            Locks::categoryTree($db);
            $row = $this->lockActive($command->category, 'for no key update');
            $parent = $command->parent?->value;

            if ($parent === Rows::nullableString($row, 'parent_id')) {
                return $this->find($command->category);
            }

            if ($command->parent instanceof CategoryId) {
                $this->lockActive($command->parent, 'for share');

                if ($this->isWithinSubtree($db, $command->category, $command->parent)) {
                    throw new CategoryCycle($command->category->value, $command->parent->value);
                }
            }

            $db->table($this->tables->table('categories'))
                ->where('id', $command->category->value)
                ->update(['parent_id' => $parent, 'updated_at' => Clock::now()]);

            return $this->find($command->category);
        });
    }

    public function archive(ArchiveCategory $command): CategoryView
    {
        return $this->transactions->run(function () use ($command): CategoryView {
            $db = $this->db();
            $table = $this->tables->table('categories');
            $row = $this->lock($command->category, 'for no key update');

            if (Rows::string($row, 'status') === CategoryStatus::Archived->value) {
                return $this->find($command->category);
            }

            $children = $db->table($table)
                ->where('parent_id', $command->category->value)
                ->where('status', CategoryStatus::Active->value)
                ->exists();

            if ($children) {
                throw new CategoryHasChildren(Rows::string($row, 'code'));
            }

            $db->table($table)
                ->where('id', $command->category->value)
                ->update(['status' => CategoryStatus::Archived->value, 'updated_at' => Clock::now()]);

            return $this->find($command->category);
        });
    }

    public function find(CategoryId $id): CategoryView
    {
        $row = $this->db()->table($this->tables->table('categories'))->where('id', $id->value)->first();

        return self::view($row ?? throw new CategoryNotFound($id->value));
    }

    public function findByCode(string $code): CategoryView
    {
        $row = $this->db()->table($this->tables->table('categories'))->where('code', $code)->first();

        return self::view($row ?? throw new CategoryNotFound($code));
    }

    public function list(ListCategories $query): Page
    {
        $builder = $this->db()->table($this->tables->table('categories'))->orderBy('code')->limit($query->first + 1);

        if ($query->parent instanceof CategoryId) {
            $builder->where('parent_id', $query->parent->value);
        }

        if ($query->roots) {
            $builder->whereNull('parent_id');
        }

        if (! $query->includeArchived) {
            $builder->where('status', CategoryStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::view(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    public function assign(AssignProductCategories $command): array
    {
        return $this->transactions->run(function () use ($command): array {
            $db = $this->db();

            // The product first, then the categories: the catalog lock order.
            $product = $db->table($this->tables->table('products'))
                ->where('id', $command->product->value)
                ->lock('for no key update')
                ->exists();

            if (! $product) {
                throw new ProductNotFound($command->product->value);
            }

            $ids = array_map(static fn (CategoryId $category): string => $category->value, $command->categories);

            if ($ids !== []) {
                // `for share` keeps them from being archived until the assignment commits.
                $rows = $db->table($this->tables->table('categories'))
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lock('for share')
                    ->get()
                    ->keyBy('id')
                    ->all();

                foreach ($ids as $id) {
                    $row = $rows[$id] ?? throw new CategoryNotFound($id);

                    if (Rows::string($row, 'status') === CategoryStatus::Archived->value) {
                        throw new CategoryArchived(Rows::string($row, 'code'));
                    }
                }
            }

            $links = $this->tables->table('category_products');
            $db->table($links)->where('product_id', $command->product->value)->delete();

            $db->table($links)->insert(array_map(static fn (string $category, int $position): array => [
                'category_id' => $category,
                'product_id' => $command->product->value,
                'position' => $position,
            ], $ids, array_keys($ids)));

            return $this->ofProduct($command->product);
        });
    }

    public function ofProduct(ProductId $product): array
    {
        $db = $this->db();

        if (! $db->table($this->tables->table('products'))->where('id', $product->value)->exists()) {
            throw new ProductNotFound($product->value);
        }

        $rows = $db->table($this->tables->table('categories').' as c')
            ->join($this->tables->table('category_products').' as l', 'l.category_id', '=', 'c.id')
            ->where('l.product_id', $product->value)
            ->orderBy('l.position')
            ->select('c.*')
            ->get()
            ->all();

        return array_values(array_map(self::view(...), $rows));
    }

    /** Whether `$candidate` is `$category` or one of its descendants. */
    private function isWithinSubtree(ConnectionInterface $db, CategoryId $category, CategoryId $candidate): bool
    {
        $table = $db->table($this->tables->table('categories'))->getGrammar()->wrapTable($this->tables->table('categories'));

        $found = $db->selectOne(
            "with recursive sub as (select id from {$table} where id = ? union all select c.id from {$table} c join sub on c.parent_id = sub.id)"
            .' select 1 as found from sub where id = ? limit 1',
            [$category->value, $candidate->value],
        );

        return $found !== null;
    }

    private function lock(CategoryId $id, string $mode): object
    {
        $row = $this->db()->table($this->tables->table('categories'))->where('id', $id->value)->lock($mode)->first();

        return $row ?? throw new CategoryNotFound($id->value);
    }

    private function lockActive(CategoryId $id, string $mode): object
    {
        $row = $this->lock($id, $mode);

        if (Rows::string($row, 'status') === CategoryStatus::Archived->value) {
            throw new CategoryArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    private static function view(object $row): CategoryView
    {
        $parent = Rows::nullableString($row, 'parent_id');

        return new CategoryView(
            new CategoryId(Rows::string($row, 'id')),
            $parent === null ? null : new CategoryId($parent),
            Rows::string($row, 'code'),
            Rows::localized($row, 'name'),
            Rows::int($row, 'position'),
            CategoryStatus::from(Rows::string($row, 'status')),
            Rows::date($row, 'created_at'),
            Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

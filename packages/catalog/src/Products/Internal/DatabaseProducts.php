<?php

declare(strict_types=1);

namespace Commerz\Catalog\Products\Internal;

use Commerz\Catalog\Attributes\Enums\AttributeStatus;
use Commerz\Catalog\Attributes\Internal\AttributeOwner;
use Commerz\Catalog\Attributes\Internal\AttributeWriter;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Clock;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Common\Internal\Rows;
use Commerz\Catalog\Products\Contracts\Products;
use Commerz\Catalog\Products\Data\ArchiveProduct;
use Commerz\Catalog\Products\Data\CreateProduct;
use Commerz\Catalog\Products\Data\ListProducts;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Data\ProductView;
use Commerz\Catalog\Products\Data\SetProductAttributes;
use Commerz\Catalog\Products\Data\UpdateProduct;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Events\ProductCreated;
use Commerz\Catalog\Products\Events\ProductUpdated;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\ProductTypes\Data\ProductTypeId;
use Commerz\Catalog\ProductTypes\Enums\ProductTypeStatus;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeArchived;
use Commerz\Catalog\ProductTypes\Exceptions\ProductTypeNotFound;
use Commerz\Configuration\CommerzConfig;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\CommittedEvents;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\TableNames;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DatabaseProducts implements Products
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzConfig $config,
        private TableNames $tables,
        private Transactions $transactions,
        private CommittedEvents $events,
        private AttributeWriter $writer,
        private ProductQuery $query,
    ) {}

    public function create(CreateProduct $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $db = $this->db();

            // `for share` keeps the type from being archived until this product is in.
            $type = $db->table($this->tables->table('product_types'))
                ->where('id', $command->type->value)
                ->lock('for share')
                ->first() ?? throw new ProductTypeNotFound($command->type->value);

            if (Rows::string($type, 'status') === ProductTypeStatus::Archived->value) {
                throw new ProductTypeArchived(Rows::string($type, 'code'));
            }

            $id = new ProductId(Uuid7::generate());
            $now = Clock::now();
            // Every attribute check runs before the first write: a refused value leaves no product behind.
            $attributes = $this->writer->prepare(AttributeOwner::product($id), $command->type, [], $command->attributes, [], creating: true);

            try {
                // A nested transaction is a savepoint: a unique violation must not poison the caller's transaction.
                $db->transaction(fn () => $db->table($this->tables->table('products'))->insert([
                    'id' => $id->value,
                    'product_type_id' => $command->type->value,
                    'handle' => $command->handle,
                    'name' => Documents::encode($command->name->texts),
                    'description' => $command->description instanceof LocalizedText ? Documents::encode($command->description->texts) : null,
                    'status' => $command->status->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Product handle', $command->handle);
            }

            // The axes are a snapshot: later changes of the type do not reach existing products.
            $db->table($this->tables->table('product_options'))->insertUsing(
                ['product_id', 'option_id', 'position'],
                $db->table($this->tables->table('product_type_options'))
                    ->selectRaw('?::uuid, option_id, position', [$id->value])
                    ->where('product_type_id', $command->type->value),
            );

            $this->writer->apply($attributes);

            $view = $this->find($id);
            $this->events->dispatch(new ProductCreated($view));

            return $view;
        });
    }

    public function update(UpdateProduct $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $db = $this->db();
            $row = $this->lockWritable($command->product);
            $changes = [];

            if ($command->handle !== null && $command->handle !== Rows::string($row, 'handle')) {
                $changes['handle'] = $command->handle;
            }

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            if ($command->description instanceof LocalizedText) {
                $changes['description'] = Documents::encode($command->description->texts);
            }

            if ($command->status instanceof ProductStatus && $command->status->value !== Rows::string($row, 'status')) {
                $changes['status'] = $command->status->value;
            }

            if ($changes === []) {
                return $this->find($command->product);
            }

            try {
                $db->transaction(fn () => $db->table($this->tables->table('products'))
                    ->where('id', $command->product->value)
                    ->update($changes + ['updated_at' => Clock::now()]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Product handle', (string) $command->handle);
            }

            return $this->updated($command->product);
        });
    }

    public function setAttributes(SetProductAttributes $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $row = $this->lockWritable($command->product);

            $this->writer->write(
                AttributeOwner::product($command->product),
                new ProductTypeId(Rows::string($row, 'product_type_id')),
                Documents::decode(Rows::string($row, 'attributes')),
                $command->values,
                $command->remove,
                creating: false,
            );

            return $this->updated($command->product);
        });
    }

    public function archive(ArchiveProduct $command): ProductView
    {
        return $this->transactions->run(function () use ($command): ProductView {
            $row = $this->lock($command->product);

            if (Rows::string($row, 'status') === ProductStatus::Archived->value) {
                return $this->find($command->product);
            }

            $this->db()->table($this->tables->table('products'))
                ->where('id', $command->product->value)
                ->update(['status' => ProductStatus::Archived->value, 'updated_at' => Clock::now()]);

            return $this->updated($command->product);
        });
    }

    public function find(ProductId $id): ProductView
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->first();

        return $this->views([$row ?? throw new ProductNotFound($id->value)])[0];
    }

    public function findByHandle(string $handle): ProductView
    {
        $row = $this->db()->table($this->tables->table('products'))->where('handle', $handle)->first();

        return $this->views([$row ?? throw new ProductNotFound($handle)])[0];
    }

    public function list(ListProducts $query): Page
    {
        $rows = $this->query->rows($query);
        $more = count($rows) > $query->first;
        $views = $this->views(array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->handle) : null);
    }

    /** `for no key update`, not `for update`: projection inserts take `for key share` on the product (FK check). */
    private function lock(ProductId $id): object
    {
        $row = $this->db()->table($this->tables->table('products'))->where('id', $id->value)->lock('for no key update')->first();

        return $row ?? throw new ProductNotFound($id->value);
    }

    private function lockWritable(ProductId $id): object
    {
        $row = $this->lock($id);

        if (Rows::string($row, 'status') === ProductStatus::Archived->value) {
            throw new ProductArchived(Rows::string($row, 'handle'));
        }

        return $row;
    }

    private function updated(ProductId $id): ProductView
    {
        $view = $this->find($id);
        $this->events->dispatch(new ProductUpdated($view));

        return $view;
    }

    /**
     * @param  list<object>  $rows
     * @return list<ProductView>
     */
    private function views(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $db = $this->db();
        $documents = [];
        $codes = [];

        foreach ($rows as $row) {
            $document = Documents::decode(Rows::string($row, 'attributes'));
            $documents[Rows::string($row, 'id')] = $document;
            array_push($codes, ...array_map(strval(...), array_keys($document)));
        }

        $retired = $this->retired($db, $codes);
        $axes = [];

        $axisRows = $db->table($this->tables->table('product_options').' as p')
            ->join($this->tables->table('options').' as o', 'o.id', '=', 'p.option_id')
            ->whereIn('p.product_id', array_keys($documents))
            ->orderBy('p.position')
            ->select('p.product_id', 'o.code')
            ->get();

        foreach ($axisRows as $axis) {
            $axes[Rows::string($axis, 'product_id')][] = Rows::string($axis, 'code');
        }

        return array_map(static function (object $row) use ($documents, $retired, $axes): ProductView {
            $id = Rows::string($row, 'id');

            return new ProductView(
                new ProductId($id),
                new ProductTypeId(Rows::string($row, 'product_type_id')),
                Rows::string($row, 'handle'),
                Rows::localized($row, 'name'),
                Rows::nullableString($row, 'description') === null ? null : Rows::localized($row, 'description'),
                ProductStatus::from(Rows::string($row, 'status')),
                self::sorted(array_diff_key($documents[$id], $retired)),
                $axes[$id] ?? [],
                Rows::date($row, 'created_at'),
                Rows::date($row, 'updated_at'),
            );
        }, $rows);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<array-key, mixed> ordered by code (JSONB keeps its own key order)
     */
    private static function sorted(array $attributes): array
    {
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * @param  list<string>  $codes
     * @return array<array-key, true> retired code => true
     */
    private function retired(ConnectionInterface $db, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $retired = $db->table($this->tables->table('attributes'))
            ->whereIn('code', array_values(array_unique($codes)))
            ->where('status', AttributeStatus::Retired->value)
            ->pluck('code')
            ->all();

        return array_fill_keys(array_filter($retired, is_string(...)), true);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

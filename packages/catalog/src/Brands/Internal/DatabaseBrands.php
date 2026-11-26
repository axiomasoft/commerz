<?php

declare(strict_types=1);

namespace Commerz\Catalog\Brands\Internal;

use Commerz\Catalog\Brands\Contracts\Brands;
use Commerz\Catalog\Brands\Data\ArchiveBrand;
use Commerz\Catalog\Brands\Data\BrandId;
use Commerz\Catalog\Brands\Data\BrandView;
use Commerz\Catalog\Brands\Data\CreateBrand;
use Commerz\Catalog\Brands\Data\ListBrands;
use Commerz\Catalog\Brands\Data\UpdateBrand;
use Commerz\Catalog\Brands\Enums\BrandStatus;
use Commerz\Catalog\Brands\Exceptions\BrandArchived;
use Commerz\Catalog\Brands\Exceptions\BrandNotFound;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Pagination\Cursor;
use Commerz\Pagination\Page;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseBrands implements Brands
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    public function create(CreateBrand $command): BrandView
    {
        return $this->transactions->run(function () use ($command): BrandView {
            $db = $this->db();
            $id = Uuid7::generate();
            $now = PgTimestamp::now($this->clock);

            try {
                $db->transaction(fn () => $db->table($this->tables->table('brands'))->insert([
                    'id' => $id,
                    'code' => $command->code,
                    'name' => Documents::encode($command->name->texts),
                    'description' => $command->description instanceof LocalizedText ? Documents::encode($command->description->texts) : null,
                    'logo_asset_id' => $command->logoAssetId,
                    'website_url' => $command->websiteUrl,
                    'status' => BrandStatus::Active->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateCode('Brand', $command->code);
            }

            return $this->find(new BrandId($id));
        });
    }

    public function update(UpdateBrand $command): BrandView
    {
        return $this->transactions->run(function () use ($command): BrandView {
            $row = $this->lockActive($command->brand);
            $changes = [];

            if ($command->name instanceof LocalizedText) {
                $changes['name'] = Documents::encode($command->name->texts);
            }

            if ($command->clearDescription) {
                $changes['description'] = null;
            } elseif ($command->description instanceof LocalizedText) {
                $changes['description'] = Documents::encode($command->description->texts);
            }

            if ($command->clearLogoAssetId) {
                $changes['logo_asset_id'] = null;
            } elseif ($command->logoAssetId !== null) {
                $changes['logo_asset_id'] = $command->logoAssetId;
            }

            if ($command->clearWebsiteUrl) {
                $changes['website_url'] = null;
            } elseif ($command->websiteUrl !== null) {
                $changes['website_url'] = $command->websiteUrl;
            }

            if ($changes !== []) {
                $this->db()->table($this->tables->table('brands'))
                    ->where('id', Rows::string($row, 'id'))
                    ->update($changes + ['updated_at' => PgTimestamp::now($this->clock)]);
            }

            return $this->find($command->brand);
        });
    }

    public function archive(ArchiveBrand $command): BrandView
    {
        return $this->transactions->run(function () use ($command): BrandView {
            $row = $this->db()->table($this->tables->table('brands'))
                ->where('id', $command->brand->value)
                ->lock('for no key update')
                ->first() ?? throw new BrandNotFound($command->brand->value);

            if (Rows::string($row, 'status') !== BrandStatus::Archived->value) {
                $this->db()->table($this->tables->table('brands'))
                    ->where('id', $command->brand->value)
                    ->update([
                        'status' => BrandStatus::Archived->value,
                        'updated_at' => PgTimestamp::now($this->clock),
                    ]);
            }

            return $this->find($command->brand);
        });
    }

    public function find(BrandId $id): BrandView
    {
        $row = $this->db()->table($this->tables->table('brands'))
            ->where('id', $id->value)
            ->first() ?? throw new BrandNotFound($id->value);

        return self::view($row);
    }

    public function findByCode(string $code): BrandView
    {
        $row = $this->db()->table($this->tables->table('brands'))
            ->where('code', $code)
            ->first() ?? throw new BrandNotFound($code);

        return self::view($row);
    }

    public function list(ListBrands $query): Page
    {
        $builder = $this->db()->table($this->tables->table('brands'))
            ->orderBy('code')
            ->limit($query->first + 1);

        if (! $query->includeArchived) {
            $builder->where('status', BrandStatus::Active->value);
        }

        if ($query->after instanceof Cursor) {
            $builder->where('code', '>', $query->after->position());
        }

        $rows = $builder->get()->all();
        $more = count($rows) > $query->first;
        $views = array_map(self::view(...), array_slice($rows, 0, $query->first));

        return new Page($views, $more && $views !== [] ? Cursor::after($views[array_key_last($views)]->code) : null);
    }

    private function lockActive(BrandId $brand): object
    {
        $row = $this->db()->table($this->tables->table('brands'))
            ->where('id', $brand->value)
            ->lock('for no key update')
            ->first() ?? throw new BrandNotFound($brand->value);

        if (Rows::string($row, 'status') === BrandStatus::Archived->value) {
            throw new BrandArchived(Rows::string($row, 'code'));
        }

        return $row;
    }

    private static function view(object $row): BrandView
    {
        return new BrandView(
            id: new BrandId(Rows::string($row, 'id')),
            code: Rows::string($row, 'code'),
            name: Rows::localized($row, 'name'),
            description: Rows::nullableString($row, 'description') !== null ? Rows::localized($row, 'description') : null,
            logoAssetId: Rows::nullableString($row, 'logo_asset_id'),
            websiteUrl: Rows::nullableString($row, 'website_url'),
            status: BrandStatus::from(Rows::string($row, 'status')),
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->tables->connection() ?? $this->config->connection);
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Internal;

use Commerz\Catalog\Common\Internal\Documents;
use Commerz\Catalog\Media\Contracts\Media;
use Commerz\Catalog\Media\Data\AssetAttachmentId;
use Commerz\Catalog\Media\Data\AssetAttachmentView;
use Commerz\Catalog\Media\Data\AttachAsset;
use Commerz\Catalog\Media\Data\DetachAsset;
use Commerz\Catalog\Media\Data\ListProductAssets;
use Commerz\Catalog\Media\Data\UpdateAssetAttachment;
use Commerz\Catalog\Media\Enums\AssetRole;
use Commerz\Catalog\Media\Exceptions\AssetAttachmentNotFound;
use Commerz\Catalog\Options\Data\OptionValueId;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Products\Enums\ProductStatus;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Configuration\CommerzOptions;
use Commerz\Identity\Uuid7;
use Commerz\Measures\LocalizedText;
use Commerz\Operations\Contracts\Transactions;
use Commerz\Schema\Rows;
use Commerz\Schema\TableNames;
use Commerz\Time\Contracts\Clock;
use Commerz\Time\PgTimestamp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseMedia implements Media
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private Transactions $transactions,
        private Clock $clock,
    ) {}

    public function attach(AttachAsset $command): AssetAttachmentView
    {
        return $this->transactions->run(function () use ($command): AssetAttachmentView {
            $db = $this->db();

            $product = $db->table($this->tables->table('products'))
                ->where('id', $command->product->value)
                ->lock('for share')
                ->first() ?? throw new ProductNotFound($command->product->value);

            if (Rows::string($product, 'status') === ProductStatus::Archived->value) {
                throw new ProductArchived(Rows::string($product, 'handle'));
            }

            if ($command->optionValue instanceof OptionValueId) {
                $this->assertOptionValueExists($db, $command->optionValue);
            }

            $id = Uuid7::generate();
            $now = PgTimestamp::now($this->clock);

            $db->table($this->tables->table('asset_attachments'))->insert([
                'id' => $id,
                'product_id' => $command->product->value,
                'option_value_id' => $command->optionValue?->value,
                'asset_id' => $command->assetId,
                'role' => $command->role->value,
                'alt' => $command->alt instanceof LocalizedText ? Documents::encode($command->alt->texts) : null,
                'position' => $command->position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->find(new AssetAttachmentId($id));
        });
    }

    public function update(UpdateAssetAttachment $command): AssetAttachmentView
    {
        return $this->transactions->run(function () use ($command): AssetAttachmentView {
            $db = $this->db();

            $row = $db->table($this->tables->table('asset_attachments'))
                ->where('id', $command->attachment->value)
                ->lock('for no key update')
                ->first() ?? throw new AssetAttachmentNotFound($command->attachment->value);

            $changes = [];

            if ($command->role instanceof AssetRole) {
                $changes['role'] = $command->role->value;
            }

            if ($command->clearAlt) {
                $changes['alt'] = null;
            } elseif ($command->alt instanceof LocalizedText) {
                $changes['alt'] = Documents::encode($command->alt->texts);
            }

            if ($command->position !== null && $command->position !== Rows::int($row, 'position')) {
                $changes['position'] = $command->position;
            }

            if ($command->clearOptionValue) {
                $changes['option_value_id'] = null;
            } elseif ($command->optionValue instanceof OptionValueId) {
                $this->assertOptionValueExists($db, $command->optionValue);
                $changes['option_value_id'] = $command->optionValue->value;
            }

            if ($changes !== []) {
                $db->table($this->tables->table('asset_attachments'))
                    ->where('id', $command->attachment->value)
                    ->update($changes + ['updated_at' => PgTimestamp::now($this->clock)]);
            }

            return $this->find($command->attachment);
        });
    }

    public function detach(DetachAsset $command): void
    {
        $deleted = $this->db()->table($this->tables->table('asset_attachments'))
            ->where('id', $command->attachment->value)
            ->delete();

        if ($deleted === 0) {
            throw new AssetAttachmentNotFound($command->attachment->value);
        }
    }

    public function find(AssetAttachmentId $id): AssetAttachmentView
    {
        $row = $this->db()->table($this->tables->table('asset_attachments'))
            ->where('id', $id->value)
            ->first() ?? throw new AssetAttachmentNotFound($id->value);

        return self::view($row);
    }

    public function ofProduct(ListProductAssets $query): array
    {
        $db = $this->db();

        if (! $db->table($this->tables->table('products'))->where('id', $query->product->value)->exists()) {
            throw new ProductNotFound($query->product->value);
        }

        $builder = $db->table($this->tables->table('asset_attachments'))
            ->where('product_id', $query->product->value)
            ->orderBy('position')
            ->orderBy('id');

        if ($query->role instanceof AssetRole) {
            $builder->where('role', $query->role->value);
        }

        if ($query->optionValue instanceof OptionValueId) {
            $builder->where('option_value_id', $query->optionValue->value);
        }

        return array_values(array_map(self::view(...), $builder->get()->all()));
    }

    private function assertOptionValueExists(ConnectionInterface $db, OptionValueId $optionValue): void
    {
        $exists = $db->table($this->tables->table('option_values'))
            ->where('id', $optionValue->value)
            ->lock('for share')
            ->exists();

        if (! $exists) {
            throw new UnknownOptionValue('option_value', $optionValue->value);
        }
    }

    private static function view(object $row): AssetAttachmentView
    {
        $optionValueId = Rows::nullableString($row, 'option_value_id');

        return new AssetAttachmentView(
            id: new AssetAttachmentId(Rows::string($row, 'id')),
            product: new ProductId(Rows::string($row, 'product_id')),
            assetId: Rows::string($row, 'asset_id'),
            role: AssetRole::from(Rows::string($row, 'role')),
            alt: Rows::nullableString($row, 'alt') !== null ? Rows::localized($row, 'alt') : null,
            position: Rows::int($row, 'position'),
            optionValue: $optionValueId !== null ? new OptionValueId($optionValueId) : null,
            createdAt: Rows::date($row, 'created_at'),
            updatedAt: Rows::date($row, 'updated_at'),
        );
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->tables->connection() ?? $this->config->connection);
    }
}

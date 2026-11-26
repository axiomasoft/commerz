<?php

declare(strict_types=1);

namespace Commerz\Catalog\Media\Contracts;

use Commerz\Catalog\Media\Data\AssetAttachmentId;
use Commerz\Catalog\Media\Data\AssetAttachmentView;
use Commerz\Catalog\Media\Data\AttachAsset;
use Commerz\Catalog\Media\Data\DetachAsset;
use Commerz\Catalog\Media\Data\ListProductAssets;
use Commerz\Catalog\Media\Data\UpdateAssetAttachment;
use Commerz\Catalog\Media\Exceptions\AssetAttachmentNotFound;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Packman\Attributes\Api;

/** Media asset attachments for products, with optional option-value scoping. */
#[Api(since: '0.1')]
interface Media
{
    /** @throws ProductNotFound|ProductArchived|UnknownOptionValue */
    public function attach(AttachAsset $command): AssetAttachmentView;

    /** @throws AssetAttachmentNotFound|UnknownOptionValue */
    public function update(UpdateAssetAttachment $command): AssetAttachmentView;

    /** @throws AssetAttachmentNotFound */
    public function detach(DetachAsset $command): void;

    /** @throws AssetAttachmentNotFound */
    public function find(AssetAttachmentId $id): AssetAttachmentView;

    /**
     * @return list<AssetAttachmentView>
     *
     * @throws ProductNotFound
     */
    public function ofProduct(ListProductAssets $query): array;
}

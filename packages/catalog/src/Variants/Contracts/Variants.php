<?php

declare(strict_types=1);

namespace Commerz\Catalog\Variants\Contracts;

use Commerz\Catalog\Attributes\Exceptions\AttributeNotAllowed;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotWritable;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeValue;
use Commerz\Catalog\Attributes\Exceptions\MissingRequiredAttributes;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Products\Exceptions\ProductArchived;
use Commerz\Catalog\Products\Exceptions\ProductNotFound;
use Commerz\Catalog\Variants\Data\ArchiveVariant;
use Commerz\Catalog\Variants\Data\CreateVariant;
use Commerz\Catalog\Variants\Data\GeneratedVariants;
use Commerz\Catalog\Variants\Data\GenerateVariants;
use Commerz\Catalog\Variants\Data\ListVariants;
use Commerz\Catalog\Variants\Data\RestoreVariant;
use Commerz\Catalog\Variants\Data\SetVariantAttributes;
use Commerz\Catalog\Variants\Data\UpdateVariant;
use Commerz\Catalog\Variants\Data\UpsertVariants;
use Commerz\Catalog\Variants\Data\UpsertVariantsResult;
use Commerz\Catalog\Variants\Data\VariantId;
use Commerz\Catalog\Variants\Data\VariantView;
use Commerz\Catalog\Variants\Exceptions\DuplicateCombination;
use Commerz\Catalog\Variants\Exceptions\InvalidAxes;
use Commerz\Catalog\Variants\Exceptions\TooManyVariants;
use Commerz\Catalog\Variants\Exceptions\VariantNotFound;
use Commerz\Errors\InvalidCommand;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution;

/**
 * Variants: what is sold. Every sellable is a variant; a combination of axis values is unique within its product
 * (CAT-01), whatever the number of concurrent requests. Each change is one transaction.
 */
#[Api(since: '0.1')]
interface Variants
{
    /**
     * @throws ProductNotFound|ProductArchived|InvalidAxes|DuplicateCombination|DuplicateCode
     * @throws AttributeNotAllowed|AttributeNotWritable|InvalidAttributeValue|MissingRequiredAttributes|UnsupportedContribution
     */
    public function create(CreateVariant $command): VariantView;

    /**
     * All or nothing: either every planned combination the product lacks is created, or none is.
     *
     * @throws ProductNotFound|ProductArchived|InvalidAxes|TooManyVariants|DuplicateCode|MissingRequiredAttributes
     * @throws InvalidCommand `catalog.invalid_sku_pattern`, `catalog.invalid_sku`
     */
    public function generate(GenerateVariants $command): GeneratedVariants;

    /** @throws VariantNotFound|ProductArchived|DuplicateCode */
    public function update(UpdateVariant $command): VariantView;

    /**
     * @throws VariantNotFound|ProductArchived
     * @throws AttributeNotAllowed|AttributeNotWritable|InvalidAttributeValue|MissingRequiredAttributes|UnsupportedContribution
     */
    public function setAttributes(SetVariantAttributes $command): VariantView;

    /** @throws VariantNotFound */
    public function archive(ArchiveVariant $command): VariantView;

    /** @throws VariantNotFound|ProductArchived */
    public function restore(RestoreVariant $command): VariantView;

    /** @throws VariantNotFound */
    public function find(VariantId $id): VariantView;

    /** @throws VariantNotFound */
    public function findBySku(string $sku): VariantView;

    /**
     * Batch upserts variants by `sku` in one atomic transaction with bounded SQL queries, grouped `cmz_attribute_index`
     * reindexing, and compressed Outbox events.
     *
     * @throws ProductNotFound|ProductArchived|InvalidAxes|DuplicateCombination|DuplicateCode|InvalidCommand
     * @throws AttributeNotAllowed|AttributeNotWritable|InvalidAttributeValue|MissingRequiredAttributes|UnsupportedContribution
     */
    public function upsertMany(UpsertVariants $command): UpsertVariantsResult;

    /**
     * @return Page<VariantView>
     *
     * @throws ProductNotFound
     */
    public function list(ListVariants $query): Page;
}

<?php

declare(strict_types=1);

namespace Commerz\Catalog\Options\Contracts;

use Commerz\Attributes\Api;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Catalog\Options\Data\AddOptionValues;
use Commerz\Catalog\Options\Data\DefineOption;
use Commerz\Catalog\Options\Data\ListOptions;
use Commerz\Catalog\Options\Data\OptionId;
use Commerz\Catalog\Options\Data\OptionView;
use Commerz\Catalog\Options\Data\UpdateOption;
use Commerz\Catalog\Options\Exceptions\OptionNotFound;
use Commerz\Catalog\Options\Exceptions\UnknownOptionValue;
use Commerz\Pagination\Page;

/** Options: the axes along which a product's variants differ (colour, size). */
#[Api(since: '0.1')]
interface Options
{
    /** @throws DuplicateCode */
    public function define(DefineOption $command): OptionView;

    /**
     * @throws OptionNotFound
     * @throws DuplicateCode
     */
    public function addValues(AddOptionValues $command): OptionView;

    /**
     * @throws OptionNotFound
     * @throws UnknownOptionValue
     */
    public function update(UpdateOption $command): OptionView;

    /** @throws OptionNotFound */
    public function find(OptionId $id): OptionView;

    /** @throws OptionNotFound */
    public function findByCode(string $code): OptionView;

    /** @return Page<OptionView> */
    public function list(ListOptions $query): Page;
}

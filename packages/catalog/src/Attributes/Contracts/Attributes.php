<?php

declare(strict_types=1);

namespace Commerz\Catalog\Attributes\Contracts;

use Commerz\Catalog\Attributes\Data\AddAttributeChoices;
use Commerz\Catalog\Attributes\Data\AttributeId;
use Commerz\Catalog\Attributes\Data\AttributeView;
use Commerz\Catalog\Attributes\Data\DefineAttribute;
use Commerz\Catalog\Attributes\Data\ListAttributes;
use Commerz\Catalog\Attributes\Data\UpdateAttribute;
use Commerz\Catalog\Attributes\Exceptions\AttributeNotFound;
use Commerz\Catalog\Attributes\Exceptions\InvalidAttributeConfig;
use Commerz\Catalog\Attributes\Exceptions\InvalidStatusTransition;
use Commerz\Catalog\Common\Exceptions\DuplicateCode;
use Commerz\Errors\UnsupportedContribution;
use Commerz\Pagination\Page;
use Packman\Attributes\Api;

/** Attribute definitions: the vocabulary product types, products and filters refer to. */
#[Api(since: '0.1')]
interface Attributes
{
    /**
     * @throws DuplicateCode
     * @throws InvalidAttributeConfig
     * @throws UnsupportedContribution unknown type
     */
    public function define(DefineAttribute $command): AttributeView;

    /**
     * @throws AttributeNotFound
     * @throws InvalidStatusTransition
     * @throws InvalidAttributeConfig
     */
    public function update(UpdateAttribute $command): AttributeView;

    /**
     * @throws AttributeNotFound
     * @throws DuplicateCode
     * @throws InvalidAttributeConfig the type has no choices
     */
    public function addChoices(AddAttributeChoices $command): AttributeView;

    /** @throws AttributeNotFound */
    public function find(AttributeId $id): AttributeView;

    /** @throws AttributeNotFound */
    public function findByCode(string $code): AttributeView;

    /** @return Page<AttributeView> */
    public function list(ListAttributes $query): Page;
}

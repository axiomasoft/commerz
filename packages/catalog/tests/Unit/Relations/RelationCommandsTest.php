<?php

declare(strict_types=1);

use Commerz\Catalog\Products\Data\ProductId;
use Commerz\Catalog\Relations\Data\AddProductRelation;
use Commerz\Catalog\Relations\Data\ReplaceProductRelations;
use Commerz\Catalog\Relations\Enums\ProductRelationType;
use Commerz\Errors\InvalidCommand;

it('validates ProductRelation commands and exposes all 5 relation types', function (): void {
    $p1 = ProductId::generate();
    $p2 = ProductId::generate();
    $p3 = ProductId::generate();

    $types = array_map(static fn (ProductRelationType $t): string => $t->value, ProductRelationType::cases());

    expect($types)->toBe(['upsell', 'cross_sell', 'spare_part', 'accessory', 'replacement']);

    $add = new AddProductRelation($p1, $p2, ProductRelationType::Upsell, 1);
    expect($add->position)->toBe(1);

    $replace = new ReplaceProductRelations($p1, ProductRelationType::CrossSell, [$p2, $p3]);
    expect($replace->targets)->toHaveCount(2);

    expect(fn () => new AddProductRelation($p1, $p1, ProductRelationType::Upsell))->toThrow(InvalidCommand::class)
        ->and(fn () => new AddProductRelation($p1, $p2, ProductRelationType::Upsell, -1))->toThrow(InvalidCommand::class)
        ->and(fn () => new ReplaceProductRelations($p1, ProductRelationType::Accessory, [$p1]))->toThrow(InvalidCommand::class)
        ->and(fn () => new ReplaceProductRelations($p1, ProductRelationType::Accessory, [$p2, $p2]))->toThrow(InvalidCommand::class);
});

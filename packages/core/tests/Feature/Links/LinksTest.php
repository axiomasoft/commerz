<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Errors\NotFound;
use Commerz\Links\Contracts\CrossModuleQuery;
use Commerz\Links\Contracts\LinkRegistry;
use Commerz\Links\Data\EntityLink;
use Commerz\Links\Data\LinkDefinition;
use Commerz\Links\Enums\CascadeUnlinkPolicy;
use Commerz\Links\Enums\LinkCardinality;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('database.connections.links_sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    config()->set('commerz.connection', 'links_sqlite');
    DB::purge('links_sqlite');

    Schema::connection('links_sqlite')->create('cmz_entity_links', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('link_code', 128);
        $table->string('left_type', 64);
        $table->string('left_id', 120);
        $table->string('right_type', 64);
        $table->string('right_id', 120);
        $table->integer('position')->default(0);
        $table->text('extra')->default('{}');
        $table->timestampTz('created_at');
        $table->timestampTz('updated_at');
        $table->unique(['link_code', 'left_type', 'left_id', 'right_type', 'right_id']);
    });

    Schema::connection('links_sqlite')->create('test_products', static function (Blueprint $table): void {
        $table->string('id', 120)->primary();
        $table->string('name');
    });

    Schema::connection('links_sqlite')->create('test_tags', static function (Blueprint $table): void {
        $table->string('id', 120)->primary();
        $table->string('label');
    });
});

it('registers definitions, enforces cardinality, and supports idempotent link updates', function (): void {
    $registry = app(LinkRegistry::class);

    $registry->define(new LinkDefinition('catalog.product_primary_image', 'product', 'media', LinkCardinality::OneToOne));
    $registry->define(new LinkDefinition('catalog.category_products', 'category', 'product', LinkCardinality::OneToMany));
    $registry->define(new LinkDefinition('catalog.product_brand', 'product', 'brand', LinkCardinality::ManyToOne));
    $registry->define(new LinkDefinition('catalog.product_tags', 'product', 'tag', LinkCardinality::ManyToMany));

    expect($registry->hasDefinition('catalog.product_tags'))->toBeTrue()
        ->and($registry->definitions())->toHaveCount(4)
        ->and(fn () => $registry->definition('unknown'))->toThrow(NotFound::class)
        ->and(fn () => $registry->define(new LinkDefinition('catalog.product_tags', 'product', 'tag')))->toThrow(InvalidCommand::class);

    // OneToOne
    $first = $registry->link('catalog.product_primary_image', 'p1', 'm1', 0, ['alt' => 'Front']);
    expect($first->extra)->toBe(['alt' => 'Front']);
    $updated = $registry->link('catalog.product_primary_image', 'p1', 'm1', 2, ['alt' => 'Updated']);
    expect($updated->id)->toBe($first->id)->and($updated->position)->toBe(2)->and($updated->extra)->toBe(['alt' => 'Updated']);

    expect(fn () => $registry->link('catalog.product_primary_image', 'p1', 'm2'))->toThrow(InvalidCommand::class, 'already has a target')
        ->and(fn () => $registry->link('catalog.product_primary_image', 'p2', 'm1'))->toThrow(InvalidCommand::class, 'already has a source');

    // OneToMany (each right belongs to at most one left)
    $registry->link('catalog.category_products', 'c1', 'p1');
    $registry->link('catalog.category_products', 'c1', 'p2');
    expect(fn () => $registry->link('catalog.category_products', 'c2', 'p1'))->toThrow(InvalidCommand::class, 'already has a source');

    // ManyToOne (each left links to at most one right)
    $registry->link('catalog.product_brand', 'p1', 'b1');
    $registry->link('catalog.product_brand', 'p2', 'b1');
    expect(fn () => $registry->link('catalog.product_brand', 'p1', 'b2'))->toThrow(InvalidCommand::class, 'already has a target');

    // Unlink
    expect($registry->unlink('catalog.product_primary_image', 'p1', 'm1'))->toBeTrue()
        ->and($registry->unlink('catalog.product_primary_image', 'p1', 'm1'))->toBeFalse();
});

it('enforces CascadeUnlinkPolicy modes (Unlink, Restrict, Ignore)', function (): void {
    $registry = app(LinkRegistry::class);

    $registry->define(new LinkDefinition('link.unlink', 'product', 'tag', LinkCardinality::ManyToMany, CascadeUnlinkPolicy::Unlink));
    $registry->define(new LinkDefinition('link.restrict', 'product', 'order', LinkCardinality::ManyToMany, CascadeUnlinkPolicy::Restrict));
    $registry->define(new LinkDefinition('link.ignore', 'product', 'audit', LinkCardinality::ManyToMany, CascadeUnlinkPolicy::Ignore));

    $registry->link('link.unlink', 'p1', 't1');
    $registry->link('link.restrict', 'p1', 'o1');
    $registry->link('link.ignore', 'p1', 'a1');

    expect(fn () => $registry->unlinkAllFor('product', 'p1'))->toThrow(InvalidCommand::class, 'restrict cascade policy');

    // Unlinking the restricted link lets unlinkAllFor succeed, deleting Unlink and preserving Ignore.
    $registry->unlink('link.restrict', 'p1', 'o1');

    expect($registry->unlinkAllFor('product', 'p1'))->toBe(1)
        ->and($registry->linksForMany('link.unlink', ['p1'])['p1'])->toBe([])
        ->and($registry->linksForMany('link.ignore', ['p1'])['p1'])->toHaveCount(1);
});

it('batch-loads linked entities for 100 entities in exactly 2 queries (N+1-free)', function (): void {
    $registry = app(LinkRegistry::class);
    $query = app(CrossModuleQuery::class);

    $registry->define(new LinkDefinition('catalog.product_tags', 'product', 'tag', LinkCardinality::ManyToMany));

    $db = DB::connection('links_sqlite');
    $tagRows = [];
    for ($t = 1; $t <= 10; $t++) {
        $tagRows[] = ['id' => "tag-{$t}", 'label' => "Tag {$t}"];
    }
    $db->table('test_tags')->insert($tagRows);

    $productIds = [];
    $productRows = [];
    for ($i = 1; $i <= 100; $i++) {
        $pid = sprintf('prod-%03d', $i);
        $productIds[] = $pid;
        $productRows[] = ['id' => $pid, 'name' => "Product {$i}"];
        $registry->link('catalog.product_tags', $pid, 'tag-'.(($i % 10) + 1), position: 2);
        $registry->link('catalog.product_tags', $pid, 'tag-'.((($i + 1) % 10) + 1), position: 1);
    }
    $db->table('test_products')->insert($productRows);

    // Forward batch load: 100 products -> exactly 2 SQL queries.
    $db->flushQueryLog();
    $db->enableQueryLog();

    $loaded = $query->getLinkedForMany(
        'catalog.product_tags',
        $productIds,
        static function (array $tagIds) use ($db): array {
            $rows = $db->table('test_tags')->whereIn('id', $tagIds)->get();
            $map = [];
            foreach ($rows as $row) {
                $map[$row->id] = $row->label;
            }

            return $map;
        },
    );

    $forwardQueries = $db->getQueryLog();
    $db->disableQueryLog();

    expect($forwardQueries)->toHaveCount(2)
        ->and($loaded)->toHaveCount(100)
        ->and($loaded['prod-001'])->toBe(['Tag 3', 'Tag 2']);

    // Inverse batch load: 10 tags -> exactly 2 SQL queries.
    $db->flushQueryLog();
    $db->enableQueryLog();

    $inverse = $query->getInverseLinkedForMany(
        'catalog.product_tags',
        array_column($tagRows, 'id'),
        static function (array $ids) use ($db): array {
            $rows = $db->table('test_products')->whereIn('id', $ids)->get();
            $map = [];
            foreach ($rows as $row) {
                $map[$row->id] = $row->name;
            }

            return $map;
        },
    );

    $inverseQueries = $db->getQueryLog();
    $db->disableQueryLog();

    expect($inverseQueries)->toHaveCount(2)
        ->and($inverse)->toHaveCount(10)
        ->and($inverse['tag-1'])->toHaveCount(20);
});

it('filters queries with CrossModuleQuery::whereLinked() forward and inverse', function (): void {
    $registry = app(LinkRegistry::class);
    $query = app(CrossModuleQuery::class);

    $registry->define(new LinkDefinition('catalog.product_tags', 'product', 'tag', LinkCardinality::ManyToMany));

    $db = DB::connection('links_sqlite');
    $db->table('test_products')->insert([
        ['id' => 'p1', 'name' => 'Alpha'],
        ['id' => 'p2', 'name' => 'Beta'],
        ['id' => 'p3', 'name' => 'Gamma'],
    ]);
    $db->table('test_tags')->insert([
        ['id' => 't1', 'label' => 'Featured'],
        ['id' => 't2', 'label' => 'Sale'],
    ]);

    $registry->link('catalog.product_tags', 'p1', 't1');
    $registry->link('catalog.product_tags', 'p2', 't2');

    $anyLinked = $query->whereLinked($db->table('test_products'), 'catalog.product_tags', 'test_products.id')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $onlyT1 = $query->whereLinked($db->table('test_products'), 'catalog.product_tags', 'test_products.id', 't1')
        ->pluck('id')
        ->all();

    $tagsOfP2 = $query->whereLinked($db->table('test_tags'), 'catalog.product_tags', 'test_tags.id', 'p2', inverse: true)
        ->pluck('id')
        ->all();

    expect($anyLinked)->toBe(['p1', 'p2'])
        ->and($onlyT1)->toBe(['p1'])
        ->and($tagsOfP2)->toBe(['t2']);

    $links = $registry->linksForMany('catalog.product_tags', ['p1', 'p3']);
    expect(array_map(static fn (EntityLink $l): string => $l->rightId, $links['p1']))->toBe(['t1'])
        ->and($links['p3'])->toBe([]);
});

<?php

declare(strict_types=1);

use Commerz\Catalog\Routes\Data\AssignCanonicalSlug;
use Commerz\Catalog\Routes\Data\ResolveRoute;
use Commerz\Catalog\Routes\Data\SeoMeta;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\Uuid7;
use Commerz\Stores\Data\ChannelId;

it('validates Route commands and round-trips SeoMeta', function (): void {
    $channel = ChannelId::generate();
    $entityId = Uuid7::generate();

    $meta = new SeoMeta(
        title: 'Blue T-Shirt',
        description: 'Soft organic cotton tee',
        robots: 'index,follow',
        ogTitle: 'Blue Tee',
        ogDescription: 'Shop now',
        ogImage: 'https://cdn.example.com/tee.jpg',
        extra: ['canonical_override' => false],
    );

    $roundTripped = SeoMeta::fromArray($meta->toArray());
    expect($roundTripped)->toEqual($meta);

    $cmd = new AssignCanonicalSlug($channel, 'en-US', 'apparel/blue-t-shirt', 'product', $entityId, $meta);
    expect($cmd->slug)->toBe('apparel/blue-t-shirt');

    expect(fn () => new AssignCanonicalSlug($channel, 'bad locale', 'blue-tee', 'product', $entityId))->toThrow(InvalidCommand::class)
        ->and(fn () => new AssignCanonicalSlug($channel, 'en', '/leading-slash', 'product', $entityId))->toThrow(InvalidCommand::class)
        ->and(fn () => new AssignCanonicalSlug($channel, 'en', 'double//slash', 'product', $entityId))->toThrow(InvalidCommand::class)
        ->and(fn () => new AssignCanonicalSlug($channel, 'en', 'UpperCase', 'product', $entityId))->toThrow(InvalidCommand::class)
        ->and(fn () => new AssignCanonicalSlug($channel, 'en', 'valid-slug', 'BadType', $entityId))->toThrow(InvalidCommand::class)
        ->and(fn () => new AssignCanonicalSlug($channel, 'en', 'valid-slug', 'product', 'not-a-uuid'))->toThrow(InvalidCommand::class)
        ->and(fn () => new ResolveRoute($channel, '', 'slug'))->toThrow(InvalidCommand::class)
        ->and(fn () => new ResolveRoute($channel, 'en', ''))->toThrow(InvalidCommand::class);
});

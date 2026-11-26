<?php

declare(strict_types=1);

use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\Metadata\Exceptions\InvalidMetadata;
use Commerz\Metadata\MetadataBag;
use Commerz\Metadata\MetadataSerializer;
use Commerz\Metadata\MetadataValidator;

it('constructs MetadataBag with isolated public and private maps and enforces visibility projections', function (): void {
    $bag = new MetadataBag(
        public: ['badge' => 'eco', 'display_rank' => 3],
        private: ['erp_sync_id' => 'SAP-9981', 'margin_tier' => 'A'],
    );

    expect($bag->isEmpty())->toBeFalse()
        ->and($bag->hasPublic('badge'))->toBeTrue()
        ->and($bag->hasPublic('erp_sync_id'))->toBeFalse()
        ->and($bag->hasPrivate('erp_sync_id'))->toBeTrue()
        ->and($bag->hasPrivate('badge'))->toBeFalse()
        ->and($bag->getPublic('badge'))->toBe('eco')
        ->and($bag->getPublic('missing', 'fallback'))->toBe('fallback')
        ->and($bag->getPrivate('erp_sync_id'))->toBe('SAP-9981')
        ->and($bag->getPrivate('missing', 'none'))->toBe('none')
        ->and($bag->forStorefront())->toBe(['badge' => 'eco', 'display_rank' => 3])
        ->and($bag->forAdmin())->toBe([
            'public' => ['badge' => 'eco', 'display_rank' => 3],
            'private' => ['erp_sync_id' => 'SAP-9981', 'margin_tier' => 'A'],
        ])
        ->and($bag->visibleTo(FieldVisibility::PublicStorefront))->toBe([
            'public' => ['badge' => 'eco', 'display_rank' => 3],
        ])
        ->and($bag->visibleTo(FieldVisibility::PrivateAdmin))->toBe([
            'public' => ['badge' => 'eco', 'display_rank' => 3],
            'private' => ['erp_sync_id' => 'SAP-9981', 'margin_tier' => 'A'],
        ])
        ->and($bag->toArray(includePrivate: false))->toBe([
            'public' => ['badge' => 'eco', 'display_rank' => 3],
        ]);

    $redacted = $bag->redactPrivate();
    expect($redacted->public)->toBe(['badge' => 'eco', 'display_rank' => 3])
        ->and($redacted->private)->toBe([])
        ->and($bag->private)->toHaveKey('erp_sync_id');
});

it('supports immutable updates, merges, removals, and equality checks', function (): void {
    $empty = MetadataBag::empty();
    expect($empty->isEmpty())->toBeTrue();

    $updated = $empty
        ->withPublic('theme', 'dark')
        ->withPublic('tags', ['featured', 'seasonal'])
        ->withPrivate('internal_note', 'VIP account')
        ->withoutPublic('theme');

    expect($updated->public)->toBe(['tags' => ['featured', 'seasonal']])
        ->and($updated->private)->toBe(['internal_note' => 'VIP account']);

    $merged = $updated
        ->mergePublic(['region' => 'EU'], ['tags'])
        ->mergePrivate(['audit_flag' => true], ['internal_note'])
        ->merge(new MetadataBag(public: ['channel_hint' => 'web'], private: ['reviewer' => 'ops']));

    expect($merged->public)->toBe(['region' => 'EU', 'channel_hint' => 'web'])
        ->and($merged->private)->toBe(['audit_flag' => true, 'reviewer' => 'ops'])
        ->and($merged->equals(MetadataBag::fromArray([
            'publicMetadata' => ['region' => 'EU', 'channel_hint' => 'web'],
            'privateMetadata' => ['audit_flag' => true, 'reviewer' => 'ops'],
        ])))->toBeTrue();

    expect(fn () => $merged->mergePublic(['region' => 'US'], ['region']))
        ->toThrow(InvalidMetadata::class, 'both set and removed')
        ->and(fn () => $merged->mergePrivate(['reviewer' => 'qa'], ['reviewer']))
        ->toThrow(InvalidMetadata::class, 'both set and removed');
});

it('serializes empty maps as JSON objects and round-trips columns and combined JSON', function (): void {
    $empty = MetadataBag::empty();
    expect($empty->toPublicJson())->toBe('{}')
        ->and($empty->toPrivateJson())->toBe('{}')
        ->and($empty->toJson())->toBe('{"public":{},"private":{}}')
        ->and($empty->toJson(includePrivate: false))->toBe('{"public":{}}');

    $bag = new MetadataBag(
        public: ['nested' => ['0' => 'zero-key', 'label' => 'Тест'], 'items' => [1, 2, 3]],
        private: ['secret.token_ref' => 'vault:abc-123'],
    );

    $columns = MetadataSerializer::encodeColumns($bag);
    expect($columns['public_metadata'])->toContain('"label":"Тест"')
        ->and(MetadataSerializer::decodeColumns((object) $columns)->equals($bag))->toBeTrue()
        ->and(MetadataSerializer::decodeColumns($columns)->equals($bag))->toBeTrue()
        ->and(MetadataBag::fromCombinedJson($bag->toJson())->equals($bag))->toBeTrue()
        ->and(MetadataBag::fromJson(null, '')->isEmpty())->toBeTrue();
});

it('rejects malformed keys, non-object JSON roots, unsupported types, excessive nesting, and oversized payloads', function (): void {
    expect(fn () => new MetadataBag(public: ['bad key' => 'v']))->toThrow(InvalidMetadata::class)
        ->and(fn () => new MetadataBag(public: ['' => 'v']))->toThrow(InvalidMetadata::class)
        ->and(fn () => new MetadataBag(public: ['list_value']))->toThrow(InvalidMetadata::class, 'non-numeric strings')
        ->and(fn () => new MetadataBag(private: ['obj' => new stdClass]))->toThrow(InvalidMetadata::class, 'unsupported value type')
        ->and(fn () => new MetadataBag(private: ['nan' => NAN]))->toThrow(InvalidMetadata::class, 'finite')
        ->and(fn () => new MetadataBag(public: ['bad_utf8' => "\xFF\xFE"]))->toThrow(InvalidMetadata::class, 'UTF-8')
        ->and(fn () => MetadataSerializer::decodeMap('[1,2,3]'))->toThrow(InvalidMetadata::class, 'object')
        ->and(fn () => MetadataSerializer::decodeMap('"string"'))->toThrow(InvalidMetadata::class, 'object')
        ->and(fn () => MetadataSerializer::decodeMap('{broken'))->toThrow(InvalidMetadata::class, 'malformed JSON')
        ->and(fn () => MetadataBag::fromArray(['unknown' => []]))->toThrow(InvalidMetadata::class, 'unexpected metadata envelope');

    $deep = 'leaf';
    for ($i = 0; $i < MetadataValidator::MAX_DEPTH + 1; $i++) {
        $deep = ['level' => $deep];
    }

    expect(fn () => new MetadataBag(public: ['root' => $deep]))->toThrow(InvalidMetadata::class, 'maximum nesting depth');

    $oversized = ['blob' => str_repeat('x', MetadataValidator::MAX_BYTES + 100)];
    expect(fn () => new MetadataBag(private: $oversized))->toThrow(InvalidMetadata::class, 'exceeds');
});

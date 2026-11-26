<?php

declare(strict_types=1);

use Commerz\CustomFields\Data\CustomFieldDefinition;
use Commerz\CustomFields\Data\CustomFieldFilter;
use Commerz\CustomFields\Enums\CustomFieldType;
use Commerz\CustomFields\Enums\FieldVisibility;
use Commerz\CustomFields\Exceptions\CustomFieldNotFound;
use Commerz\CustomFields\Exceptions\CustomFieldVisibilityViolation;
use Commerz\CustomFields\Exceptions\DuplicateCustomField;
use Commerz\CustomFields\Exceptions\InvalidCustomFieldValue;
use Commerz\CustomFields\Exceptions\MissingRequiredCustomFields;
use Commerz\CustomFields\Internal\InMemoryCustomFieldRegistry;
use Commerz\Errors\Enums\CommerzErrorCode;
use Commerz\Errors\InvalidCommand;
use Commerz\Identity\CustomerRef;
use Commerz\Measures\LocalizedText;
use Commerz\Metadata\MetadataBag;

it('validates CustomFieldDefinition invariants, rules, and localized labels', function (): void {
    $definition = new CustomFieldDefinition(
        targetAggregate: 'order',
        namespace: 'crm',
        key: 'deal_stage',
        type: CustomFieldType::Enum,
        visibility: FieldVisibility::PrivateAdmin,
        isFilterable: true,
        isRequired: true,
        validationRules: ['choices' => ['lead', 'qualified', 'won']],
        localizedLabel: (new LocalizedText(['en' => 'Deal Stage', 'de' => 'Verkaufsphase'])),
    );

    expect($definition->qualifiedKey())->toBe('crm.deal_stage')
        ->and($definition->storageKey())->toBe('crm:deal_stage')
        ->and($definition->label('de'))->toBe('Verkaufsphase')
        ->and($definition->label('fr'))->toBe('Deal Stage')
        ->and($definition->isVisibleTo(FieldVisibility::PrivateAdmin))->toBeTrue()
        ->and($definition->isVisibleTo(FieldVisibility::PublicStorefront))->toBeFalse();

    expect(fn () => new CustomFieldDefinition('Order-Bad', 'crm', 'k', CustomFieldType::String))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new CustomFieldDefinition('order', 'Bad Namespace', 'k', CustomFieldType::String))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new CustomFieldDefinition('order', 'crm', 'Bad Key', CustomFieldType::String))
        ->toThrow(InvalidCommand::class)
        ->and(fn () => new CustomFieldDefinition('order', 'crm', 'payload', CustomFieldType::Json, isFilterable: true))
        ->toThrow(InvalidCommand::class, 'cmz_custom_field_index')
        ->and(fn () => new CustomFieldDefinition('order', 'crm', 'title', CustomFieldType::LocalizedString, isFilterable: true))
        ->toThrow(InvalidCommand::class, 'cmz_custom_field_index')
        ->and(fn () => new CustomFieldDefinition('order', 'crm', 'qty', CustomFieldType::Integer, validationRules: ['min' => 50, 'max' => 10]))
        ->toThrow(InvalidCommand::class, 'min must not exceed max')
        ->and(fn () => new CustomFieldDefinition('order', 'crm', 'code', CustomFieldType::String, validationRules: ['pattern' => '[unclosed']))
        ->toThrow(InvalidCommand::class, 'PCRE');
});

it('registers definitions deterministically, prevents duplicates, and separates visibility per audience', function (): void {
    $registry = new InMemoryCustomFieldRegistry;

    $publicFilterable = new CustomFieldDefinition(
        targetAggregate: 'product',
        namespace: 'merch',
        key: 'season',
        type: CustomFieldType::Enum,
        visibility: FieldVisibility::PublicStorefront,
        isFilterable: true,
        validationRules: ['in:spring,summer,autumn,winter'],
    );
    $publicNonFilterable = new CustomFieldDefinition(
        targetAggregate: 'product',
        namespace: 'merch',
        key: 'care_note',
        type: CustomFieldType::LocalizedString,
        visibility: FieldVisibility::PublicStorefront,
        isFilterable: false,
    );
    $privateFilterable = new CustomFieldDefinition(
        targetAggregate: 'product',
        namespace: 'erp',
        key: 'supplier_score',
        type: CustomFieldType::Integer,
        visibility: FieldVisibility::PrivateAdmin,
        isFilterable: true,
        validationRules: ['min' => 0, 'max' => 100],
    );

    $registry->register($publicNonFilterable, $privateFilterable, $publicFilterable);

    expect($registry->has('product', 'merch', 'season'))->toBeTrue()
        ->and($registry->has('product', 'merch', 'missing'))->toBeFalse()
        ->and($registry->get('product', 'erp', 'supplier_score'))->toBe($privateFilterable)
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->forAggregate('product')))
        ->toBe(['erp.supplier_score', 'merch.care_note', 'merch.season'])
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->forAggregate('product', FieldVisibility::PublicStorefront)))
        ->toBe(['merch.care_note', 'merch.season'])
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->forAggregate('product', FieldVisibility::PrivateAdmin)))
        ->toBe(['erp.supplier_score'])
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->visibleTo('product', FieldVisibility::PublicStorefront)))
        ->toBe(['merch.care_note', 'merch.season'])
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->visibleTo('product', FieldVisibility::PrivateAdmin)))
        ->toBe(['erp.supplier_score', 'merch.care_note', 'merch.season'])
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->filterableFor('product', FieldVisibility::PublicStorefront)))
        ->toBe(['merch.season'])
        ->and(array_map(fn (CustomFieldDefinition $d): string => $d->qualifiedKey(), $registry->filterableFor('product', FieldVisibility::PrivateAdmin)))
        ->toBe(['erp.supplier_score', 'merch.season']);

    expect(fn () => $registry->register($publicFilterable))
        ->toThrow(function (DuplicateCustomField $e): void {
            expect($e->errorDescriptor()->errorCode)->toBe(CommerzErrorCode::DuplicateCode);
        });

    expect(fn () => $registry->get('product', 'erp', 'unknown'))
        ->toThrow(function (CustomFieldNotFound $e): void {
            expect($e->errorDescriptor()->errorCode)->toBe(CommerzErrorCode::NotFound);
        });
});

it('normalizes all custom field types, routes values by visibility, and enforces required and audience invariants', function (): void {
    $registry = new InMemoryCustomFieldRegistry;
    $registry->register(
        new CustomFieldDefinition('customer', 'loyalty', 'tier', CustomFieldType::Enum, FieldVisibility::PublicStorefront, isFilterable: true, isRequired: true, validationRules: ['choices' => ['bronze', 'silver', 'gold']]),
        new CustomFieldDefinition('customer', 'loyalty', 'badges', CustomFieldType::MultiEnum, FieldVisibility::PublicStorefront, isFilterable: true, validationRules: ['choices' => ['founder', 'beta', 'vip']]),
        new CustomFieldDefinition('customer', 'loyalty', 'discount_factor', CustomFieldType::Decimal, FieldVisibility::PublicStorefront, isFilterable: true, validationRules: ['scale' => 2, 'min' => '0.00', 'max' => '50.00']),
        new CustomFieldDefinition('customer', 'profile', 'bio', CustomFieldType::LocalizedString, FieldVisibility::PublicStorefront),
        new CustomFieldDefinition('customer', 'profile', 'birth_date', CustomFieldType::Date, FieldVisibility::PublicStorefront, isFilterable: true),
        new CustomFieldDefinition('customer', 'profile', 'newsletter', CustomFieldType::Boolean, FieldVisibility::PublicStorefront, isFilterable: true),
        new CustomFieldDefinition('customer', 'crm', 'account_manager', CustomFieldType::EntityRef, FieldVisibility::PrivateAdmin, isFilterable: true, validationRules: ['allowed_types' => ['staff', 'user']]),
        new CustomFieldDefinition('customer', 'crm', 'credit_score', CustomFieldType::Integer, FieldVisibility::PrivateAdmin, isFilterable: true, validationRules: ['min' => 300, 'max' => 850]),
        new CustomFieldDefinition('customer', 'crm', 'tax_code', CustomFieldType::String, FieldVisibility::PrivateAdmin, isFilterable: false, validationRules: ['min_length' => 4, 'max_length' => 16, 'pattern' => '/\A[A-Z0-9\-]+\z/']),
        new CustomFieldDefinition('customer', 'crm', 'kyc_payload', CustomFieldType::Json, FieldVisibility::PrivateAdmin),
    );

    $prepared = $registry->prepareValues('customer', [
        'loyalty.tier' => 'gold',
        'loyalty:badges' => ['vip', 'founder', 'vip'],
        'loyalty.discount_factor' => '12.5',
        'profile.bio' => ['en' => 'Collector', 'de' => 'Sammler'],
        'profile.birth_date' => new DateTimeImmutable('1992-06-15'),
        'profile.newsletter' => true,
        'crm.account_manager' => CustomerRef::of('user', 'mgr-42'),
        'crm.credit_score' => '780',
        'crm.tax_code' => 'DE-998877',
        'crm.kyc_payload' => ['verified_by' => 'compliance', 'level' => 2],
    ]);

    expect($prepared->metadata->public)->toBe([
        'loyalty.tier' => 'gold',
        'loyalty.badges' => ['vip', 'founder'],
        'loyalty.discount_factor' => '12.50',
        'profile.bio' => ['de' => 'Sammler', 'en' => 'Collector'],
        'profile.birth_date' => '1992-06-15',
        'profile.newsletter' => true,
    ])
        ->and($prepared->metadata->private)->toBe([
            'crm.account_manager' => 'user:mgr-42',
            'crm.credit_score' => 780,
            'crm.tax_code' => 'DE-998877',
            'crm.kyc_payload' => ['verified_by' => 'compliance', 'level' => 2],
        ])
        // Filterable facts: tier(1) + badges(2) + discount_factor(1) + birth_date(1) + newsletter(1) + account_manager(1) + credit_score(1) = 8 rows.
        ->and($prepared->rows)->toHaveCount(8);

    // Reject float on Decimal type (ADR D8).
    expect(fn () => $registry->prepareValues('customer', ['loyalty.tier' => 'gold', 'loyalty.discount_factor' => 12.5]))
        ->toThrow(InvalidCustomFieldValue::class, 'floats are forbidden');

    // Reject missing required field on creation.
    expect(fn () => $registry->prepareValues('customer', ['profile.newsletter' => false], creating: true))
        ->toThrow(MissingRequiredCustomFields::class, 'loyalty.tier');

    // Reject removing a required field on update.
    expect(fn () => $registry->prepareValues('customer', [], current: $prepared->metadata, remove: ['loyalty.tier'], creating: false))
        ->toThrow(MissingRequiredCustomFields::class, 'loyalty.tier');

    // Reject storefront audience attempting to write or remove a PrivateAdmin custom field.
    expect(fn () => $registry->prepareValues(
        'customer',
        ['crm.credit_score' => 800],
        current: $prepared->metadata,
        creating: false,
        actorAudience: FieldVisibility::PublicStorefront,
    ))->toThrow(CustomFieldVisibilityViolation::class);

    // Reject a MetadataBag that leaks a PrivateAdmin field into publicMetadata.
    $leaked = new MetadataBag(
        public: ['loyalty.tier' => 'gold', 'crm.credit_score' => 750],
        private: [],
    );
    expect(fn () => $registry->validateMetadata('customer', $leaked))
        ->toThrow(CustomFieldVisibilityViolation::class, 'must not be stored in publicMetadata');

    // Storefront projection strips privateMetadata AND defense-in-depth scrubs any PrivateAdmin key from publicMetadata.
    $projected = $registry->projectMetadata('customer', $leaked, FieldVisibility::PublicStorefront);
    expect($projected->public)->toBe(['loyalty.tier' => 'gold'])
        ->and($projected->private)->toBe([]);

    // CustomFieldFilter validation.
    expect(fn () => CustomFieldFilter::equals('crm', 'credit_score', null))->toThrow(InvalidCommand::class)
        ->and(fn () => CustomFieldFilter::in('crm', 'credit_score', []))->toThrow(InvalidCommand::class)
        ->and(fn () => CustomFieldFilter::between('crm', 'credit_score', null, null))->toThrow(InvalidCommand::class);
});

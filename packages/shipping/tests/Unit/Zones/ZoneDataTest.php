<?php

declare(strict_types=1);

use Commerz\Errors\InvalidCommand;
use Commerz\Shipping\Tests\TestCase;
use Commerz\Shipping\Zones\Data\CreateZone;
use Commerz\Shipping\Zones\Data\ListZones;
use Commerz\Shipping\Zones\Data\UpdateZone;
use Commerz\Shipping\Zones\Data\ZoneId;
use Commerz\Shipping\Zones\Data\ZoneMember;
use Commerz\Shipping\Zones\Enums\MemberKind;

uses(TestCase::class);

it('builds members of the three kinds', function (): void {
    $country = ZoneMember::country('RU');
    $region = ZoneMember::region('RU-MOW');
    $prefix = ZoneMember::postalPrefix('RU', '101');

    expect($country->kind())->toBe(MemberKind::Country)
        ->and($region->kind())->toBe(MemberKind::Region)
        ->and($region->country)->toBe('RU')
        ->and($region->region)->toBe('RU-MOW')
        ->and($prefix->kind())->toBe(MemberKind::PostalPrefix)
        ->and($prefix->postalPrefix)->toBe('101');
});

it('normalizes a postal prefix', function (): void {
    expect(ZoneMember::postalPrefix('GB', 'sw-1a ')->postalPrefix)->toBe('SW1A')
        ->and(ZoneMember::postalPrefix('RU', '1-01 ')->postalPrefix)->toBe('101');
});

it('refuses malformed members', function (string $build): void {
    expect(fn () => match ($build) {
        'country' => ZoneMember::country('ru'),
        'region' => ZoneMember::region('RU_MOW'),
        'region-long' => ZoneMember::region('RU-MOSC'),
        'prefix-country' => ZoneMember::postalPrefix('Russia', '101'),
        'prefix-empty' => ZoneMember::postalPrefix('RU', ' - '),
        'prefix-chars' => ZoneMember::postalPrefix('RU', '10%'),
        'prefix-long' => ZoneMember::postalPrefix('RU', '12345678901'),
        default => throw new LogicException("Unexpected member build '{$build}'."),
    })->toThrow(InvalidCommand::class);
})->with(['country', 'region', 'region-long', 'prefix-country', 'prefix-empty', 'prefix-chars', 'prefix-long']);

it('takes the country of a region from its code', function (): void {
    expect(ZoneMember::region('DE-BE')->country)->toBe('DE');
});

it('refuses a member listed twice, however it is spelled', function (): void {
    expect(fn () => new CreateZone('ru', 'Russia', [ZoneMember::postalPrefix('RU', '101'), ZoneMember::postalPrefix('RU', '1-01')]))
        ->toThrow(InvalidCommand::class, 'listed twice');

    $zone = new CreateZone('ru', 'Russia', [ZoneMember::country('RU'), ZoneMember::region('RU-MOW'), ZoneMember::postalPrefix('RU', '101')]);

    expect($zone->members)->toHaveCount(3);
});

it('validates the code and the name', function (): void {
    expect(fn () => new CreateZone('Bad Code', 'Zone'))->toThrow(InvalidCommand::class)
        ->and(fn () => new CreateZone('ok', ' '))->toThrow(InvalidCommand::class)
        ->and(fn () => new UpdateZone(new ZoneId('x'), str_repeat('я', 192)))->toThrow(InvalidCommand::class)
        ->and((new CreateZone('ok', str_repeat('я', 191)))->name)->toHaveLength(191);
});

it('validates the list query', function (): void {
    expect(fn () => new ListZones(country: 'ru'))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListZones(limit: 0))->toThrow(InvalidCommand::class)
        ->and(fn () => new ListZones(limit: 201))->toThrow(InvalidCommand::class);
});

<?php

declare(strict_types=1);

use Commerz\Arch\ApiManifest;
use Commerz\Arch\ApiSurface;
use Packman\DevTools\Generators\Contracts\ApiManifestGenerator;

// The SemVer snapshot of 0.1: every package's public PHP surface in packages/<pkg>/resources/contracts/php-api.json.
// `COMMERZ_UPDATE_API_MANIFEST=1` writes the manifests (composer api:manifest); otherwise a stale one fails here.

final class ApiManifestProbe
{
    public const array LEVELS = ['low' => 1, 'high' => [2, 3]];

    public static function implicit(): self
    {
        return new self;
    }

    public static function explicit(): ApiManifestProbe
    {
        return new self;
    }

    /** @param  int|list<string>|null  $value */
    public function shapes(?int $value = null, int|string ...$rest): ?static
    {
        return null;
    }
}

interface ApiManifestParentProbe {}

interface ApiManifestChildProbe extends ApiManifestParentProbe {}

abstract class ApiManifestOpenProbe
{
    public private(set) array $log = [];

    public protected(set) int $count = 0;

    final public function sealed(int $max = PHP_INT_MAX): void {}

    public function same(self $other, ?self $maybe = null): bool
    {
        return true;
    }
}

it('writes signatures as declared, whatever the PHP version resolves', function (): void {
    $manifest = new ApiManifest(ApiSurface::fromRoot(dirname(__DIR__, 2)));

    expect($manifest->signature(new ReflectionMethod(ApiManifestProbe::class, 'implicit')))->toBe('static implicit(): self')
        ->and($manifest->signature(new ReflectionMethod(ApiManifestProbe::class, 'explicit')))->toBe('static explicit(): \\ApiManifestProbe')
        ->and($manifest->signature(new ReflectionMethod(ApiManifestProbe::class, 'shapes')))->toBe('shapes(?int $value = null, string|int ...$rest): ?static')
        ->and(ApiManifest::literal(ApiManifestProbe::LEVELS))->toBe("['low' => 1, 'high' => [2, 3]]")
        // `final`, a global constant default, and `self` in parameters as written (PHP 8.5 reflection resolves it).
        ->and($manifest->signature(new ReflectionMethod(ApiManifestOpenProbe::class, 'sealed')))->toBe('final sealed(int $max = \\PHP_INT_MAX): void')
        ->and($manifest->signature(new ReflectionMethod(ApiManifestOpenProbe::class, 'same')))->toBe('same(self $other, ?self $maybe = null): bool');
});

it('records asymmetric visibility, property defaults and the parents of an interface', function (): void {
    $manifest = new ApiManifest(ApiSurface::fromRoot(dirname(__DIR__, 2)));

    expect($manifest->members(new ReflectionClass(ApiManifestOpenProbe::class))['properties'])->toBe([
        'count' => 'public protected(set) int $count = 0',
        'log' => 'public private(set) array $log = []',
    ])
        ->and($manifest->members(new ReflectionClass(ApiManifestChildProbe::class))['extends'])->toBe([ApiManifestParentProbe::class]);
});

it('refuses a type without a marker', function (): void {
    (new ApiManifest(ApiSurface::fromRoot(dirname(__DIR__, 2))))->type(new ReflectionClass(ApiManifestProbe::class));
})->throws(RuntimeException::class, 'ApiManifestProbe has neither #[Api] nor #[Spi].');

it('keeps every php-api.json manifest in sync with the code', function (): void {
    $root = dirname(__DIR__, 2);
    $update = getenv('COMMERZ_UPDATE_API_MANIFEST') === '1';

    $packages = [
        ['name' => 'axiomasoft/commerz', 'path' => 'packages/core'],
        ['name' => 'axiomasoft/commerz-testkit', 'path' => 'packages/testkit'],
        ['name' => 'axiomasoft/commerz-catalog', 'path' => 'packages/catalog'],
        ['name' => 'axiomasoft/commerz-inventory', 'path' => 'packages/inventory'],
        ['name' => 'axiomasoft/commerz-pricing', 'path' => 'packages/pricing'],
        ['name' => 'axiomasoft/commerz-promotions', 'path' => 'packages/promotions'],
        ['name' => 'axiomasoft/commerz-shipping', 'path' => 'packages/shipping'],
        ['name' => 'axiomasoft/commerz-sales', 'path' => 'packages/sales'],
    ];

    $packmanSync = (new ApiManifestGenerator)->sync($root, $packages, write: $update);
    expect($packmanSync['ok'])->toBeTrue(implode("\n", $packmanSync['diagnostics']));

    // No orphan manifest for a package that has no code any more.
    $expected = array_map(static fn (array $package): string => $root.'/'.$package['path'].'/resources/contracts/php-api.json', $packages);

    expect(array_values(array_diff(glob($root.'/packages/*/resources/contracts/php-api.json') ?: [], $expected)))->toBe([]);
});

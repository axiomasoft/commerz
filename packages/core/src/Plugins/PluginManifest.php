<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Attributes\Api;
use Commerz\Errors\InvalidConfiguration;

/** Identity and dependencies of a plugin. `requires` must be present; `optional` only orders loading when present. */
#[Api(since: '0.1')]
final readonly class PluginManifest
{
    public const string ID_PATTERN = '/\A[a-z0-9]+(\.[a-z0-9-]+)+\z/';

    private const string SEMVER_PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(-((0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(\.(0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(\+([0-9a-zA-Z-]+(\.[0-9a-zA-Z-]+)*))?\z/';

    /**
     * @param  string  $id  `vendor.name`, lowercase letters, digits, dots and dashes
     * @param  string  $version  semver
     * @param  list<string>  $requires  plugin ids that must be registered
     * @param  list<string>  $optional  plugin ids loaded first when they are registered
     */
    public function __construct(
        public string $id,
        public string $version,
        public array $requires = [],
        public array $optional = [],
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidConfiguration('plugin.manifest.id', "'{$id}' is not a plugin id (vendor.name, lowercase letters, digits, dots and dashes)");
        }

        if (preg_match(self::SEMVER_PATTERN, $version) !== 1) {
            throw new InvalidConfiguration('plugin.manifest.version', "'{$version}' is not a semver version");
        }

        self::assertIds('requires', $id, $requires);
        self::assertIds('optional', $id, $optional);

        if (array_intersect($requires, $optional) !== []) {
            throw new InvalidConfiguration('plugin.manifest.optional', 'a plugin cannot be both required and optional');
        }
    }

    /** @param  list<mixed>  $ids */
    private static function assertIds(string $field, string $self, array $ids): void
    {
        $seen = [];

        foreach ($ids as $id) {
            if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new InvalidConfiguration("plugin.manifest.{$field}", 'expected plugin ids (vendor.name)');
            }

            if ($id === $self) {
                throw new InvalidConfiguration("plugin.manifest.{$field}", "plugin '{$self}' lists itself");
            }

            if (isset($seen[$id])) {
                throw new InvalidConfiguration("plugin.manifest.{$field}", "'{$id}' is listed twice");
            }

            $seen[$id] = true;
        }
    }
}

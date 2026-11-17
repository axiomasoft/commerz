<?php

declare(strict_types=1);

use Commerz\Plugins\CorePlugin;
use Packman\Attributes\Api as PackmanApi;
use Packman\Attributes\Spi as PackmanSpi;
use Packman\Attributes\SpiKind as PackmanSpiKind;
use Packman\Plugins\Contracts\Contributions\Contributions as PackmanContributions;
use Packman\Plugins\Contracts\Registry\PluginInspector as PackmanPluginInspector;
use Packman\Plugins\Data\Contributions\ContributionDescription as PackmanContributionDescription;
use Packman\Plugins\Data\Contributions\ContributionKind as PackmanContributionKind;
use Packman\Plugins\Data\Plugin\PluginDescription as PackmanPluginDescription;
use Packman\Plugins\Data\Plugin\PluginManifest as PackmanPluginManifest;
use Packman\Plugins\Enums\Plugin\PluginSource as PackmanPluginSource;
use Packman\Plugins\Exceptions\Registry\UnsupportedContribution as PackmanUnsupportedContribution;
use Packman\Schema\Contracts\Compiler\SchemaOwnership as PackmanSchemaOwnership;
use Packman\Schema\Data\Compiler\ConstraintNames as PackmanConstraintNames;
use Packman\Schema\Data\Compiler\TableNames as PackmanTableNames;
use Packman\Schema\Laravel\PackmanMigration;

// Temporary Slice-A compatibility aliases while domain subpackages still target legacy Commerz\* symbols.
$aliases = [
    PackmanApi::class => 'Commerz\\Attributes\\Api',
    PackmanSpi::class => 'Commerz\\Attributes\\Spi',
    PackmanSpiKind::class => 'Commerz\\Attributes\\SpiKind',
    PackmanUnsupportedContribution::class => 'Commerz\\Errors\\UnsupportedContribution',
    CorePlugin::class => 'Commerz\\Internal\\CorePlugin',
    PackmanContributionDescription::class => 'Commerz\\Plugins\\ContributionDescription',
    PackmanContributionKind::class => 'Commerz\\Plugins\\ContributionKind',
    PackmanPluginDescription::class => 'Commerz\\Plugins\\PluginDescription',
    PackmanPluginManifest::class => 'Commerz\\Plugins\\PluginManifest',
    PackmanPluginSource::class => 'Commerz\\Plugins\\PluginSource',
    PackmanContributions::class => 'Commerz\\Plugins\\Contracts\\Contributions',
    PackmanPluginInspector::class => 'Commerz\\Plugins\\Contracts\\PluginInspector',
    PackmanMigration::class => 'Commerz\\Schema\\CommerzMigration',
    PackmanConstraintNames::class => 'Commerz\\Schema\\ConstraintNames',
    PackmanSchemaOwnership::class => 'Commerz\\Schema\\Contracts\\SchemaOwnership',
    PackmanTableNames::class => 'Commerz\\Schema\\TableNames',
];

foreach ($aliases as $target => $alias) {
    if (! class_exists($alias, false) && ! interface_exists($alias, false) && ! enum_exists($alias, false)) {
        class_alias($target, $alias);
    }
}

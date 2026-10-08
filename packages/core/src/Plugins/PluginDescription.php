<?php

declare(strict_types=1);

namespace Commerz\Plugins;

use Commerz\Attributes\Api;

#[Api(since: '0.1')]
final readonly class PluginDescription
{
    /**
     * @param  class-string  $class
     * @param  list<string>  $requires
     * @param  list<string>  $optional
     * @param  list<ContributionKind>  $kinds  kinds this plugin declared
     * @param  list<ContributionDescription>  $contributions
     */
    public function __construct(
        public string $id,
        public string $version,
        public PluginSource $source,
        public string $class,
        public array $requires,
        public array $optional,
        public array $kinds,
        public array $contributions,
    ) {}
}

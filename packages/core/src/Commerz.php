<?php

declare(strict_types=1);

namespace Commerz;

use Commerz\Attributes\Api;
use Commerz\Plugins\Contracts\PluginInspector;
use Commerz\Stores\Contracts\Channels;
use Commerz\Stores\Contracts\Stores;

/** Root of the public API: only accessors of domain contracts. Feature packages add their own roots. */
#[Api(since: '0.1')]
final readonly class Commerz
{
    public function __construct(
        private Stores $stores,
        private Channels $channels,
        private PluginInspector $plugins,
    ) {}

    public function stores(): Stores
    {
        return $this->stores;
    }

    public function channels(): Channels
    {
        return $this->channels;
    }

    public function plugins(): PluginInspector
    {
        return $this->plugins;
    }
}

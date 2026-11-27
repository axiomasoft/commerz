<?php

declare(strict_types=1);

namespace Commerz\Inventory\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Inventory\InventoryServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Illuminate\Testing\PendingCommand;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, InventoryServiceProvider::class];
    }

    /**
     * Artisan tests mock console output, so the command is a pending assertion, not an exit code.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function pendingArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}

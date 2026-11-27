<?php

declare(strict_types=1);

namespace Commerz\Catalog\Tests;

use Commerz\Catalog\CatalogServiceProvider;
use Commerz\CommerzServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Illuminate\Testing\PendingCommand;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, CatalogServiceProvider::class];
    }

    /**
     * Artisan tests mock console output, so the command is a pending assertion, not an exit code.
     * The command runs when this object is destroyed, which a chained call does at the end of the statement.
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

<?php

declare(strict_types=1);

namespace Commerz\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Illuminate\Testing\PendingCommand;
use RuntimeException;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class];
    }

    /** @param array<mixed> $parameters */
    protected function pendingArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            throw new RuntimeException("Expected a pending command for [{$command}].");
        }

        return $pending;
    }
}

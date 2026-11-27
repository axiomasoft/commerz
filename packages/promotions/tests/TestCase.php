<?php

declare(strict_types=1);

namespace Commerz\Promotions\Tests;

use Commerz\CommerzServiceProvider;
use Commerz\Promotions\PromotionsServiceProvider;
use Commerz\Testkit\PackageTestCase;
use Illuminate\Testing\PendingCommand;

abstract class TestCase extends PackageTestCase
{
    protected function commerzProviders(): array
    {
        return [CommerzServiceProvider::class, PromotionsServiceProvider::class];
    }

    /** @param array<string, mixed> $parameters */
    protected function pendingArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}

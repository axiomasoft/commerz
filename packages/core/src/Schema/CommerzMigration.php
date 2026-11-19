<?php

declare(strict_types=1);

namespace Commerz\Schema;

use Illuminate\Container\Container;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Data\Compiler\TableNames as PackmanTables;
use Packman\Schema\Laravel\PackmanMigration;

/** Runs migrations with the Commerz table prefix and connection. */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
abstract class CommerzMigration extends PackmanMigration
{
    #[Override]
    public function getConnection(): ?string
    {
        return $this->tables()->connection();
    }

    #[Override]
    protected function table(string $logical): string
    {
        return $this->tables()->table($logical);
    }

    #[Override]
    protected function tables(): PackmanTables
    {
        return Container::getInstance()->make(TableNames::class)->resolver();
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Pricing\Schema;

use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Contracts\Config\Repository;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Schema\Contracts\Migration\SchemaSetProvider;
use Packman\Schema\Data\Migration\SchemaSet;

/**
 * Declares the PostgreSQL schema set owned by `axiomasoft/commerz-pricing`.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class PricingSchemaSets implements SchemaSetProvider
{
    public function __construct(
        private ?Repository $config = null,
    ) {}

    /** @return list<SchemaSet> */
    #[Override]
    public function sets(): array
    {
        return [
            CommerzSchemaSets::forPackage(
                config: $this->config,
                package: 'axiomasoft/commerz-pricing',
                name: 'commerz-pricing',
                path: dirname(__DIR__, 2).'/database/migrations',
                version: 2,
            ),
        ];
    }
}

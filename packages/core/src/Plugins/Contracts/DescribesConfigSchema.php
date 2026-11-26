<?php

declare(strict_types=1);

namespace Commerz\Plugins\Contracts;

use Commerz\Plugins\Data\ConfigFieldSchema;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional interface implemented by plugins that declare a typed configuration schema
 * for host validation and admin UI generation.
 */
#[Spi(kind: SpiKind::Implement, since: '0.1')]
interface DescribesConfigSchema
{
    /** @return list<ConfigFieldSchema> */
    public function configSchema(): array;
}

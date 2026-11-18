<?php

declare(strict_types=1);

// Пакет: axiomasoft/commerz — src/Attributes/{Api,Spi,SpiKind}.php (здесь в одном файле для компактности примера).
// Маркировка публичной поверхности как в legacy-platform (API-001) и Chatom (#[Api]/#[Spi]).

namespace Commerz\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Api
{
    public function __construct(public string $since) {}
}

enum SpiKind: string
{
    case Implement = 'implement'; // хост/плагин реализует
    case Call = 'call';           // хост/плагин вызывает
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Spi
{
    public function __construct(public SpiKind $kind, public string $since) {}
}

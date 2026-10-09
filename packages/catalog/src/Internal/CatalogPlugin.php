<?php

declare(strict_types=1);

namespace Commerz\Catalog\Internal;

use Commerz\Catalog\Attributes\Contracts\AttributeType;
use Commerz\Catalog\Attributes\Internal\Types\BooleanType;
use Commerz\Catalog\Attributes\Internal\Types\DateType;
use Commerz\Catalog\Attributes\Internal\Types\DecimalType;
use Commerz\Catalog\Attributes\Internal\Types\EnumType;
use Commerz\Catalog\Attributes\Internal\Types\IntegerType;
use Commerz\Catalog\Attributes\Internal\Types\LocalizedTextType;
use Commerz\Catalog\Attributes\Internal\Types\MeasureType;
use Commerz\Catalog\Attributes\Internal\Types\MultiEnumType;
use Commerz\Catalog\Attributes\Internal\Types\ReferenceType;
use Commerz\Catalog\Attributes\Internal\Types\TextType;
use Commerz\Catalog\ProductTypes\Contracts\ProductKind;
use Commerz\Catalog\ProductTypes\Internal\Kinds\DigitalKind;
use Commerz\Catalog\ProductTypes\Internal\Kinds\PhysicalKind;
use Commerz\Catalog\ProductTypes\Internal\Kinds\ServiceKind;
use Commerz\Catalog\Variants\Internal\VariantSellables;
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

/** The catalog's own plugin: declares the `attribute_type` and `product_kind` kinds, contributes the built-ins and the `variant` sellable provider. */
final class CatalogPlugin implements CommerzPlugin
{
    public const string ID = 'commerz.catalog';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(AttributeType::KIND, AttributeType::class, self::ID));

        $registrar->declareKind(new ContributionKind(ProductKind::KIND, ProductKind::class, self::ID));

        foreach ([
            TextType::class, LocalizedTextType::class, IntegerType::class, DecimalType::class, BooleanType::class,
            DateType::class, EnumType::class, MultiEnumType::class, MeasureType::class, ReferenceType::class,
        ] as $type) {
            $registrar->contribute(AttributeType::KIND, $type);
        }

        foreach ([PhysicalKind::class, DigitalKind::class, ServiceKind::class] as $kind) {
            $registrar->contribute(ProductKind::KIND, $kind);
        }

        $registrar->sellableProvider(VariantSellables::class);
    }
}

<?php

declare(strict_types=1);

namespace Commerz\Catalog\Panels;

use Commerz\Catalog\Panels\Internal\CatalogPanelGate;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Panels\Contracts\Registry\PanelProvider;
use Packman\Panels\Data\Admission\PanelAccess;
use Packman\Panels\Data\Panel\PanelContext;
use Packman\Panels\Data\Panel\PanelDescriptor;
use Packman\Panels\Data\Panel\PanelReference;

/**
 * Headless Packman panel provider descriptor and admission gate for catalog categories.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final readonly class CategoriesPanel implements PanelProvider
{
    /** @var list<string> */
    private const array SUPPORTED_ACTIONS = ['view', 'create', 'update', 'archive'];

    #[Override]
    public function descriptor(): PanelDescriptor
    {
        return new PanelDescriptor(
            version: 'panel/1',
            key: 'commerz.catalog.categories',
            owningModule: 'axiomasoft/commerz-catalog',
            requiredCapability: null,
            supportedActions: self::SUPPORTED_ACTIONS,
            label: 'Categories',
        );
    }

    #[Override]
    public function access(PanelContext $context, PanelReference $panel): PanelAccess
    {
        return CatalogPanelGate::evaluate($context, self::SUPPORTED_ACTIONS);
    }
}

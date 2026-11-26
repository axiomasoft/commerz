<?php

declare(strict_types=1);

namespace Commerz\B2b\Laravel\Registrars;

use Commerz\B2b\Approvals\Contracts\ApprovalFlows;
use Commerz\B2b\Approvals\Internal\DatabaseApprovalFlows;
use Commerz\B2b\B2b;
use Commerz\B2b\BusinessUnits\Contracts\BusinessUnits;
use Commerz\B2b\BusinessUnits\Internal\DatabaseBusinessUnits;
use Commerz\B2b\Configuration\B2bOptions;
use Commerz\B2b\Diagnostics\B2bDoctorCheck;
use Commerz\B2b\PaymentTerms\Contracts\CreditAccounts;
use Commerz\B2b\PaymentTerms\Contracts\CreditLimitValidator;
use Commerz\B2b\PaymentTerms\Contracts\PaymentTermsCatalog;
use Commerz\B2b\PaymentTerms\Internal\DatabaseCreditAccounts;
use Commerz\B2b\PaymentTerms\Internal\DatabasePaymentTermsCatalog;
use Commerz\B2b\PaymentTerms\Internal\DefaultCreditLimitValidator;
use Commerz\B2b\Plugins\B2bPlugin;
use Commerz\B2b\Quotes\Contracts\B2bQuotes;
use Commerz\B2b\Quotes\Contracts\OrderPlacementPort;
use Commerz\B2b\Quotes\Internal\DatabaseB2bQuotes;
use Commerz\B2b\Quotes\Internal\DefaultOrderPlacementPort;
use Commerz\B2b\Quotes\Internal\LockedPriceScope;
use Commerz\B2b\Quotes\Internal\LockedQuotePriceStage;
use Commerz\Schema\CommerzSchemaSets;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Wires Commerz B2B services, plugin, options, schema ownership, diagnostics, and default order placement port.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class B2bRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(B2bPlugin::class);

        $app->singleton(B2bDoctorCheck::class, static fn (Application $app): B2bDoctorCheck => new B2bDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): B2bOptions => $app->make(B2bOptions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(LockedPriceScope::class);
        $app->singleton(LockedQuotePriceStage::class);
        $app->singletonIf(OrderPlacementPort::class, DefaultOrderPlacementPort::class);

        $app->singleton(BusinessUnits::class, DatabaseBusinessUnits::class);
        $app->singleton(ApprovalFlows::class, DatabaseApprovalFlows::class);
        $app->singleton(PaymentTermsCatalog::class, DatabasePaymentTermsCatalog::class);
        $app->singleton(CreditAccounts::class, DatabaseCreditAccounts::class);
        $app->singleton(CreditLimitValidator::class, DefaultCreditLimitValidator::class);
        $app->singleton(B2bQuotes::class, DatabaseB2bQuotes::class);
        $app->singleton(B2b::class);
    }
}

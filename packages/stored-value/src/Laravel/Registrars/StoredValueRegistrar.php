<?php

declare(strict_types=1);

namespace Commerz\StoredValue\Laravel\Registrars;

use Commerz\Ports\StoredValue\Contracts\StoredValueTender;
use Commerz\Schema\CommerzSchemaSets;
use Commerz\StoredValue\Configuration\StoredValueOptions;
use Commerz\StoredValue\Diagnostics\StoredValueDoctorCheck;
use Commerz\StoredValue\GiftCards\Contracts\GiftCards;
use Commerz\StoredValue\GiftCards\Internal\DatabaseGiftCards;
use Commerz\StoredValue\Ledger\Contracts\StoredValueLedger;
use Commerz\StoredValue\Ledger\Internal\DoubleEntryLedger;
use Commerz\StoredValue\Plugins\StoredValuePlugin;
use Commerz\StoredValue\StoredValue;
use Commerz\StoredValue\Tender\Internal\DatabaseStoredValueTender;
use Commerz\StoredValue\Wallets\Contracts\CustomerWallets;
use Commerz\StoredValue\Wallets\Internal\DatabaseCustomerWallets;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Wires Commerz stored-value services, extension plugin, options, schema ownership, diagnostics, and port override.
 */
#[Spi(kind: SpiKind::Call, since: '0.1')]
final class StoredValueRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singletonIf(StoredValuePlugin::class);

        $app->singleton(StoredValueDoctorCheck::class, static fn (Application $app): StoredValueDoctorCheck => new StoredValueDoctorCheck(
            config: $app->make(ConfigRepository::class),
            optionsResolver: static fn (): StoredValueOptions => $app->make(StoredValueOptions::class),
        ));

        CommerzSchemaSets::governMigrations($app);

        $app->singleton(DoubleEntryLedger::class);
        $app->singleton(StoredValueLedger::class, DoubleEntryLedger::class);
        $app->singleton(GiftCards::class, DatabaseGiftCards::class);
        $app->singleton(CustomerWallets::class, DatabaseCustomerWallets::class);
        $app->singleton(StoredValueTender::class, DatabaseStoredValueTender::class); // replaces core singletonIf null default
        $app->singleton(StoredValue::class);
    }
}

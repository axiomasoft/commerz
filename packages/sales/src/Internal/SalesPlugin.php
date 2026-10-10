<?php

declare(strict_types=1);

namespace Commerz\Sales\Internal;

use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\ContributionKind;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;
use Commerz\Sales\Gateways\Contracts\PaymentGateway;
use Commerz\Sales\Gateways\Internal\ManualGateway;
use Commerz\Sales\Numbering\Contracts\OrderNumberGenerator;
use Commerz\Sales\Numbering\Internal\SequenceOrderNumbers;

/** The sales' own plugin: declares the `payment_gateway` and `order_number_generator` kinds and contributes the `manual` gateway and the `sequence` numbering. */
final class SalesPlugin implements CommerzPlugin
{
    public const string ID = 'commerz.sales';

    public function manifest(): PluginManifest
    {
        return new PluginManifest(self::ID, '0.1.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->declareKind(new ContributionKind(PaymentGateway::KIND, PaymentGateway::class, self::ID));
        $registrar->declareKind(new ContributionKind(OrderNumberGenerator::KIND, OrderNumberGenerator::class, self::ID));
        $registrar->contribute(PaymentGateway::KIND, ManualGateway::class);
        $registrar->contribute(OrderNumberGenerator::KIND, SequenceOrderNumbers::class);
    }
}

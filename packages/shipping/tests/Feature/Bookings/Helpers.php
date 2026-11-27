<?php

declare(strict_types=1);

// Shared fixtures of the booking tests. Booking refuses to run inside a transaction (SHP-04), so these tests cannot
// use RefreshDatabase: they commit and empty the tables after each test.

use Commerz\Errors\CommerzException;
use Commerz\Money\Money;
use Commerz\Plugins\CorePlugin;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\Address;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Shipping\Bookings\Exceptions\BookingInProgress;
use Commerz\Shipping\Carriers\Contracts\Carrier;
use Commerz\Shipping\Plugins\ShippingPlugin;
use Commerz\Shipping\Tests\Fixtures\RecordingCarrier;
use Commerz\Shipping\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Packman\Errors\Exceptions\CodedException;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Laravel\PluginRegistrar;

require_once __DIR__.'/../Methods/Helpers.php';

/** Registers the recording carrier before the application boots. */
trait BkEnvironment
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('commerz.plugins', [BkPlugin::class]);
    }
}

abstract class BkEnvironmentTestCase extends TestCase
{
    use BkEnvironment;
}

final class BkPlugin implements ExtensionPlugin
{
    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest('test.shipping-bookings', '1.0.0', [CorePlugin::ID, ShippingPlugin::ID]);
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->contribute(Carrier::KIND, RecordingCarrier::class);
    }
}

/** Migrates, empties the carrier log and creates the methods `recorded` (recording carrier) and `courier` (manual). */
function bkSetUp(): void
{
    mtMigrate();
    bkTruncate();
    RecordingCarrier::reset();
    mtMethod('recorded', ['carrier' => RecordingCarrier::KEY]);
    mtMethod('courier');
}

function bkTruncate(): void
{
    DB::statement('truncate cmz_shipping_bookings, cmz_shipping_rates, cmz_shipping_method_channels, cmz_shipping_methods,'
        .' cmz_shipping_zone_members, cmz_shipping_zones cascade');
}

/** @param  array<string, scalar>  $meta */
function bkCommand(string $reference = 'fulfillment:1', array $meta = [], string $method = 'recorded', string $carrier = RecordingCarrier::KEY, string $city = 'Moscow'): BookShipment
{
    return new BookShipment(
        $reference,
        $carrier,
        $method,
        new Address('RU', 'RU-MOW', $city, '101000', ['Tverskaya 1'], 'Ivan', '+70000000000'),
        new Parcel(1200, Money::of('1500.00', 'RUB')),
        $meta,
    );
}

function bkBook(BookShipment $command): BookedShipment
{
    return app(ShipmentBooking::class)->book($command);
}

/** How a booking ends: `booked <tracking>`, `in_progress`, or the error code of another Commerz error. */
function bkOutcome(BookShipment $command): string
{
    try {
        return 'booked '.bkBook($command)->tracking;
    } catch (BookingInProgress) {
        return 'in_progress';
    } catch (CommerzException $e) {
        return $e->code();
    } catch (CodedException $e) {
        return $e->errorDescriptor()->errorCode->value();
    }
}

/**
 * @return object{
 *     id: string,
 *     state: string,
 *     attempts: int,
 *     tracking: string|null,
 *     error_code: string|null,
 *     error_message: string|null,
 *     booked_at: string|null,
 *     method_code: string,
 *     label_ref: string|null,
 *     result: string|null
 * }
 */
function bkRow(string $reference = 'fulfillment:1'): object
{
    /** @var list<object{
     *     id: string,
     *     state: string,
     *     attempts: int,
     *     tracking: string|null,
     *     error_code: string|null,
     *     error_message: string|null,
     *     booked_at: string|null,
     *     method_code: string,
     *     label_ref: string|null,
     *     result: string|null
     * }> $rows
     */
    $rows = DB::select(
        'select id, state, attempts, tracking, error_code, error_message, booked_at, method_code, label_ref, result'
        .' from cmz_shipping_bookings where reference = ?',
        [$reference],
    );

    return $rows[0] ?? throw new RuntimeException("No booking '{$reference}'.");
}

/** @return array<mixed> */
function bkDecoded(mixed $result): array
{
    if (! is_string($result)) {
        throw new RuntimeException('expected a stored booking result');
    }

    $decoded = json_decode($result, true);

    if (! is_array($decoded)) {
        throw new RuntimeException('expected a JSON object result');
    }

    return $decoded;
}

/** Moves the lease of a booking into the past, as if its owner died long ago. */
function bkExpireLease(string $reference = 'fulfillment:1'): void
{
    DB::table('cmz_shipping_bookings')->where('reference', $reference)->update(['claimed_at' => '2000-01-01 00:00:00+00']);
}

/**
 * @param  list<string>  $outcomes
 * @return array<string, int>
 */
function bkTally(array $outcomes): array
{
    $counts = array_count_values($outcomes);
    ksort($counts);

    return $counts;
}

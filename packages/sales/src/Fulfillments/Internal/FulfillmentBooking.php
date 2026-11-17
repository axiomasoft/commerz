<?php

declare(strict_types=1);

namespace Commerz\Sales\Fulfillments\Internal;

use Commerz\Configuration\CommerzOptions;
use Commerz\Money\Currency;
use Commerz\Money\Money;
use Commerz\Money\MoneyAllocator;
use Commerz\Ports\Delivery\Contracts\ShipmentBooking;
use Commerz\Ports\Delivery\Data\BookedShipment;
use Commerz\Ports\Delivery\Data\BookShipment;
use Commerz\Ports\Delivery\Data\Parcel;
use Commerz\Sales\Common\Internal\Rows;
use Commerz\Sales\Common\Internal\SalesCodec;
use Commerz\Sales\Fulfillments\Data\BookFulfillment;
use Commerz\Sales\Fulfillments\Data\FulfillmentView;
use Commerz\Sales\Fulfillments\Exceptions\FulfillmentNotBookable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Packman\Attributes\Internal;
use Packman\Schema\Data\Compiler\TableNames;

/**
 * The only caller of `ShipmentBooking` (PAY-02's twin, D14/D29/D30): builds the booking of a fulfillment from the order
 * as it was placed and sends it. Called outside any transaction, without a lock; the journal of the shipping package
 * deduplicates by the reference `fulfillment:{id}`, so a repeat after a lost answer is safe.
 */
#[Internal]
final readonly class FulfillmentBooking
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private CommerzOptions $config,
        private TableNames $tables,
        private ShipmentBooking $carriers,
    ) {}

    /**
     * What the fulfillment ships: the carrier and method of the delivery the order was placed with, its shipping
     * address, and a parcel of the lines that need shipping (their weight, and the declared value of the fulfilled
     * share of each line total, split exactly).
     *
     * @throws FulfillmentNotBookable
     */
    public function request(FulfillmentView $fulfillment, BookFulfillment $command): BookShipment
    {
        $id = $fulfillment->id->value;
        $order = $this->db()->table($this->tables->table('orders'))->where('id', $fulfillment->order->value)->first(['currency', 'delivery', 'shipping_address']);
        $delivery = $order === null ? null : Rows::nullableString($order, 'delivery');
        $address = $order === null ? null : Rows::nullableString($order, 'shipping_address');

        if ($order === null || $delivery === null || $address === null) {
            throw new FulfillmentNotBookable($id, 'no_delivery');
        }

        $currency = Currency::of(Rows::string($order, 'currency'));
        $weight = 0;
        $value = 0;
        $ships = false;

        foreach ($this->db()->table($this->tables->table('fulfillment_lines').' as fl')
            ->join($this->tables->table('order_lines').' as ol', 'ol.id', '=', 'fl.order_line_id')
            ->where('fl.fulfillment_id', $id)
            ->orderBy('fl.order_line_id')
            ->get(['fl.quantity as shipped', 'ol.quantity', 'ol.weight_grams', 'ol.total_minor', 'ol.requires_shipping']) as $line) {
            if (! Rows::bool($line, 'requires_shipping')) {
                continue;
            }

            $ships = true;
            $shipped = Rows::int($line, 'shipped');
            $weight += (Rows::nullableInt($line, 'weight_grams') ?? 0) * $shipped;
            $value += MoneyAllocator::allocate(
                Money::ofMinor(Rows::int($line, 'total_minor'), $currency),
                [$shipped, Rows::int($line, 'quantity') - $shipped],
            )[0]->amountMinor;
        }

        if (! $ships) {
            throw new FulfillmentNotBookable($id, 'nothing_to_ship');
        }

        $charge = SalesCodec::deliveryFromJson($delivery, $currency);

        return new BookShipment(
            'fulfillment:'.$id,
            $charge->carrier,
            $charge->methodCode,
            SalesCodec::addressFromJson($address),
            new Parcel($weight, Money::ofMinor($value, $currency), $command->lengthMm, $command->widthMm, $command->heightMm),
            $command->meta,
        );
    }

    /** Asks the carrier; its exceptions are the caller's. */
    public function send(BookShipment $request): BookedShipment
    {
        return $this->carriers->book($request);
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->config->connection);
    }
}

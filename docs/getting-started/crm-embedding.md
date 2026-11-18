# Embedding in a CRM

A CRM does not want a catalog, stock or a shipping module: it already has
entities to sell — services, subscriptions, deals — and its own customers. The
install is a **subset**: the core, `commerz-pricing` (taxes and B2B prices) and
`commerz-sales`. The scenario below is the verified test
`tests/EndToEnd/CrmScenarioTest.php`.

```bash
composer require axiomasoft/commerz axiomasoft/commerz-pricing axiomasoft/commerz-sales
```

## What the host provides

| Port | Why | Config |
|---|---|---|
| `SellableProvider` | turns a `SellableRef` (`crm.service:audit`) into a snapshot: name, kind, base price, tax class | registered by a plugin |
| `CustomerDirectory` | turns a `CustomerRef` (`crm.account:acme`) into a profile with customer groups | `commerz.customers` |

The ports of absent packages stay on the core defaults: unlimited stock, no
promotions, no delivery. `packman:doctor` confirms the installation.

## Configuration

```php
// config/commerz.php
'stock'     => 'unlimited',
'customers' => App\Crm\CrmCustomers::class,
'plugins'   => [App\Crm\CrmPlugin::class],
```

Services carry `requiresShipping: false`, so no delivery method is demanded and
`require_delivery` can stay at its default. Set it to `false` only for a CRM
that sells physical goods without the shipping package.

## The sellable provider

```php
use Commerz\Context\CommerceContext;
use Commerz\Identity\SellableRef;
use Commerz\Money\Money;
use Commerz\Ports\Sellables\Contracts\SellableProvider;
use Commerz\Ports\Sellables\Data\SellableSnapshot;
use Commerz\Ports\Sellables\Enums\SellableKind;

final class CrmServices implements SellableProvider
{
    public const string KEY = 'crm.service';

    public function key(): string
    {
        return self::KEY;
    }

    public function resolve(array $refs, CommerceContext $context): array
    {
        $snapshots = [];

        foreach ($refs as $ref) {
            $service = Service::query()->where('code', $ref->id)->first();   // your own table

            if ($service === null) {
                continue;                       // unknown refs are left out; the router reports them unavailable
            }

            $snapshots[(string) $ref] = new SellableSnapshot(
                $ref,
                'SRV-'.strtoupper($ref->id),
                $service->name,
                SellableKind::Service,
                requiresShipping: false,
                basePrice: Money::of($service->tariff, 'RUB'),
                taxClass: 'standard',
            );
        }

        return $snapshots;
    }
}
```

and the plugin that registers it:

```php
use Commerz\Plugins\Contracts\CommerzPlugin;
use Commerz\Plugins\PluginManifest;
use Commerz\Plugins\PluginRegistrar;

final class CrmPlugin implements CommerzPlugin
{
    public function manifest(): PluginManifest
    {
        return new PluginManifest('acme.crm', '1.0.0');
    }

    public function register(PluginRegistrar $registrar): void
    {
        $registrar->sellableProvider(CrmServices::class);
    }
}
```

## The customer directory

```php
use Commerz\Identity\CustomerRef;
use Commerz\Ports\Customers\Contracts\CustomerDirectory;
use Commerz\Ports\Customers\Data\CustomerProfile;

final class CrmCustomers implements CustomerDirectory
{
    public function find(CustomerRef $customer): ?CustomerProfile
    {
        $account = Account::query()->find($customer->id);

        return $account === null
            ? null                                     // unknown means a guest
            : new CustomerProfile($customer, $account->groups, $account->billing_email, $account->name);
    }
}
```

Customer groups drive customer-group prices and promotions; the CRM names only
the account and Commerz reads its groups from the directory.

## From draft to completed

A manager works on a **draft** — a cart of kind `draft` that has no payment
deadline — and places it on behalf of an actor.

```php
$manager = ActorRef::of('user', 'manager-5');

$draft = Sales::orders()->draft(new DraftOrder(
    CommerceContext::for($channel, $rub, 'ru'),
    [new DraftLine(CrmServices::ref('consulting'), 2), new DraftLine(CrmServices::ref('audit'), 1)],
    actor: $manager,
));
Sales::carts()->setCustomer(new SetCartCustomer($draft->id, CustomerRef::fromString('crm.account:acme')));

$quote = Sales::carts()->quote($draft->id);
$order = Sales::checkout()->place(new PlaceOrder($draft->id, $quote->total, actor: $manager));
```

- Prices come from pricing: a contract price list for the `b2b` group, and the
  CRM's own tariff as the fallback (`commerz-pricing.fallback_to_sellable`), so
  the CRM never copies its tariffs into Commerz.
- Taxes are added by pricing's `tax` stage. Without a delivery address a CRM
  taxes in its home zone: `commerz-pricing.tax.default_zone`.
- The order number is `<store prefix><sequence>`: with `new CreateStore('crm', 'CRM', $rub, 'CRM-')` the first order is `CRM-1001`.

Payment and fulfillment are the same calls as in the [shop](./quick-start-shop):

```php
$payment = Sales::payments()->initiate(new InitiatePayment($order->id, 'manual'));
Sales::payments()->recordEvent(new RecordPaymentEvent('manual', 'crm-bank-1', PaymentEventType::Captured, paymentReference: $payment->reference));

Sales::fulfillments()->create(new CreateFulfillment($order->id, $allLinesInFull));
// order state: Completed, payment state: Paid, fulfillment state: Fulfilled
```

Rendering the services fulfils every line; unlimited stock has nothing to
consume. The `manual` gateway is for offline payments and invoices: an
operator's event confirms the money.

## Mixing in a package later

Adding `commerz-inventory` or `commerz-shipping` to a CRM later is a Composer
require and a migration: their service providers override the core defaults of
their ports. Switch `commerz.stock` back to `inventory` when stock should be
tracked. See [ports and plugins](../concepts/ports-and-plugins).

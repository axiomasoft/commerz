# Configuration

Every key of every package. All files are merged with the defaults, so publishing
is optional. Values are validated at boot; an invalid value is an
`InvalidConfiguration`. How-to: [configure Commerz](../how-to/configure).

## commerz (core) — `config/commerz.php`

| Key | Default | Description |
|---|---|---|
| `connection` | `null` | Database connection for all Commerz tables; `null` is the application default |
| `table_prefix` | `cmz_` | Prefix of every table: 2..7 lowercase letters or digits, then `_`. Choose it before the first migration; changing it later is a boot error |
| `stock` | `inventory` | `inventory` checks sales against the inventory package; `unlimited` skips stock tracking |
| `customers` | `null` | Class implementing `CustomerDirectory`, or `null` when the host has no customer groups |
| `plugins` | `[]` | Plugin classes implementing `CommerzPlugin`, registered in order |
| `operations.receipt_ttl_days` | `30` | Days an idempotency receipt (and a delivered outbox message) is kept |

## commerz-catalog — `config/commerz-catalog.php`

| Key | Default | Description |
|---|---|---|
| `max_generated_variants` | `100` | Upper bound of variants one `generate()` call may create (1..10000) |

## commerz-inventory — `config/commerz-inventory.php`

| Key | Default | Description |
|---|---|---|
| `allocation_strategy` | `priority` | Key of the `allocation_strategy` contribution deciding which location holds a reservation: `priority` (fills channel sources in priority order, may split a line), `single_location` (one location for the whole reservation) or a plugin key such as `acme.nearest` |
| `unlinked_sellables` | `unavailable` | What a sellable without any stock item link is: `unavailable` (reserving fails) or `unlimited` (always available, nothing held) |
| `expiry_batch` | `500` | Reservations the expiry sweeper releases per pass (1..10000) |

## commerz-pricing — `config/commerz-pricing.php`

| Key | Default | Description |
|---|---|---|
| `fallback_to_sellable` | `true` | With no applicable price in the channel's lists, fall back to the sellable's own base price (only when its currency is the context currency); otherwise `prices.price_not_found` |
| `tax.calculator` | `rates` | Key of the contributed `tax_calculator` the tax stage calls |
| `tax.default_class` | `standard` | Tax class of a sellable that names none |
| `tax.delivery_class` | `standard` | Tax class of the delivery charge; `null` leaves delivery untaxed |
| `tax.default_zone` | `null` | Tax zone code used when a quote has no delivery address (POS, CRM, digital goods); `null`: no zone, no tax |
| `tax.rounding` | `half_up` | `half_up`, `half_even`, `half_down`, `up`, `down`, `ceiling` or `floor` |

## commerz-promotions — `config/commerz-promotions.php`

| Key | Default | Description |
|---|---|---|
| `rounding` | `half_up` | How a percentage effect rounds to the minor unit (same modes as tax) |
| `max_codes` | `5` | Codes read from one quote; the codes past the limit are rejected as `too_many_codes` |
| `restore_on` | `['cancelled', 'expired']` | Reasons for which `PromotionUsage::restore` gives the usage back: `cancelled`, `expired`, `refunded` |
| `coupons.alphabet` | `23456789ABCDEFGHJKMNPQRSTUVWXYZ` | Characters a `#` in a generation pattern takes: 2 to 64 distinct characters of `A-Z` and `0-9` |
| `coupons.max_generate` | `50000` | The most coupons one `GenerateCoupons` command may create |

## commerz-shipping — `config/commerz-shipping.php`

| Key | Default | Description |
|---|---|---|
| `tax_included` | `false` | Whether a rate's price includes tax when `ZoneRate::$taxIncluded` is left `null` |
| `max_zone_members` | `2000` | The most members (countries, regions, postal prefixes) one zone command may carry (1..100000) |
| `booking.lease_seconds` | `120` | Seconds a fresh `pending` booking counts as in progress (10..3600); must exceed the carrier's request timeout |

## commerz-sales — `config/commerz-sales.php`

| Key | Default | Description |
|---|---|---|
| `carts.max_lines` | `250` | Lines in one cart (1..10000) |
| `carts.max_quantity` | `100000` | Quantity of one line (1..1000000) |
| `checkout.require_delivery` | `true` | Lines that require shipping need a selected delivery method; `false` for a CRM without shipping |
| `checkout.number_generator` | `sequence` | Key of the `order_number_generator` contribution |
| `orders.payment_due_minutes` | `4320` | `null` or 1..525600: minutes to pay an order placed from a `cart`; drafts have no deadline |
| `orders.expire_batch` | `100` | Orders expired in one pass (1..1000) |
| `payments.lease_seconds` | `120` | Lease of a network operation (10..3600); keep it above the HTTP timeout of the gateway client |

## Environment

The core reads no environment variables itself. Database access is the host's
connection (`DB_*` of Laravel). The testkit reads `DB_HOST`, `DB_PORT`,
`DB_DATABASE` (default `commerz_test`), `DB_USERNAME` and `DB_PASSWORD`.

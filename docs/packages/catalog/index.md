# axiomasoft/commerz-catalog

Products, variants, options, attributes, categories and per-channel listings.
The catalog is a **sellable provider**: its variants are the sellables a shop
puts into carts (`SellableRef` type `variant`). It owns no prices and no stock.

## Install

```bash
composer require axiomasoft/commerz-catalog
```

Requires the core. Provider `Commerz\Catalog\CatalogServiceProvider`.

## Configuration

`--tag=commerz-catalog-config` → `config/commerz-catalog.php`.

| Key | Default | Meaning |
|---|---|---|
| `max_generated_variants` | `100` | Most variants one `generate()` call may create (1..10000) |

## Root and facade

`Commerz\Catalog\Catalog` (facade `Commerz\Catalog\Facades\Catalog`):

| Accessor | Methods |
|---|---|
| `products()` | `create`, `update`, `setAttributes`, `archive`, `find`, `findByHandle`, `list` |
| `variants()` | `create`, `generate`, `update`, `setAttributes`, `archive`, `restore`, `find`, `findBySku`, `list` |
| `options()` | `define`, `addValues`, `update`, `find`, `findByCode`, `list` |
| `attributes()` | `define`, `update`, `addChoices`, `find`, `findByCode`, `list` |
| `productTypes()` | `create`, `update`, `archive`, `find`, `findByCode`, `list` |
| `categories()` | `create`, `update`, `move`, `archive`, `assign`, `ofProduct`, `find`, `findByCode`, `list` |
| `listings()` | `publish`, `unpublish`, `find`, `ofProduct` |

A **product type** fixes the variant axes (options) and which attributes
products and variants may carry; `generate()` produces every combination of
option values for a product. Attributes are typed (see kinds), filterable ones
are projected into `cmz_attribute_index`, and a search filter such as
`red AND M` matches **one variant** having both.

## Kinds

| Kind | Contract | Built-in keys |
|---|---|---|
| `attribute_type` | `AttributeType` | `text`, `localized_text`, `integer`, `decimal`, `boolean`, `date`, `enum`, `multi_enum`, `measure`, `reference` |
| `product_kind` | `ProductKind` | `physical`, `digital`, `service` |

Plugin `commerz.catalog`. It also registers the `variant` sellable provider.
A plugin contributing an `attribute_type` requires `commerz.catalog`. An
attribute whose type is not installed stays readable as a stored value,
unprojected and read-only.

## Tables

Prefix + `products`, `product_types`, `product_type_attributes`,
`product_type_options`, `product_options`, `options`, `option_values`,
`variants`, `variant_option_values`, `attributes`, `attribute_choices`,
`attribute_index`, `categories`, `category_products`, `listings`.

## Commands

None.

## Events

Laravel events dispatched after commit: `ProductCreated`, `ProductUpdated`,
`ProductPublished`, `ProductUnpublished`, `VariantCreated`, `VariantUpdated`.
The catalog publishes no outbox events and calls no network. See the
[events reference](../../reference/events).

## Notes

- A variant is purchasable in a channel only while published and inside its
  window; otherwise the provider reports `not_listed`, `not_available_yet` or
  `no_longer_available`.
- Repeating a command is safe through natural keys: `code`, `handle`, `sku`,
  the option signature.

API reference: [catalog](../../reference/api/catalog).

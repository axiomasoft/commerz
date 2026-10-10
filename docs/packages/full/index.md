# axiomasoft/commerz-full

A metapackage with no code. It requires the core and all six feature packages
at `^0.1`, for an online store that wants everything:

```bash
composer require axiomasoft/commerz-full
```

| Required | |
|---|---|
| `axiomasoft/commerz` | [core](../core/) |
| `axiomasoft/commerz-catalog` | [catalog](../catalog/) |
| `axiomasoft/commerz-inventory` | [inventory](../inventory/) |
| `axiomasoft/commerz-pricing` | [pricing](../pricing/) |
| `axiomasoft/commerz-promotions` | [promotions](../promotions/) |
| `axiomasoft/commerz-shipping` | [shipping](../shipping/) |
| `axiomasoft/commerz-sales` | [sales](../sales/) |

The testkit is not included: add `axiomasoft/commerz-testkit` with `--dev`.

A host that needs less requires the core and the packages it uses — see
[embedding in a CRM](../../getting-started/crm-embedding). There is no API of
its own; the [API reference](../../reference/api/) covers the packages it pulls in.

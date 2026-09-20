# Warehouse / Trading API

Laravel 10 + MySQL. Products are bought from providers in batches, stored in storages, and
sold to clients. Refunds work in both directions, fully or partially.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# set DB_DATABASE in .env and create that schema in MySQL
php artisan migrate --seed
php artisan serve
```

The seeder builds the `Ahmad Tea → Black/Green/White Tea` tree from the spec, a second
provider to exercise the provider/category rule, 2 storages and 3 clients.

## Schema

| Table | Purpose |
|---|---|
| `providers` | Companies we buy from |
| `categories` | Self-referencing tree. `parent_id IS NULL` marks a root; only roots carry `provider_id` |
| `products` | Belong to one category. `price` is the **selling** price |
| `storages` | Where goods sit |
| `clients` | Companies we sell to |
| `batches` | One purchase from one provider into one storage, dated `purchased_at` |
| `batch_items` | Batch lines: product, qty, `purchase_price` |
| `orders` | One sale to one client, dated `ordered_at` |
| `order_items` | Order lines, each pointing at the `batch_item` it was picked from |
| `refunds` | Both directions in one table, distinguished by `type` |

### Design notes

**No stock table.** Stock is derived from the four events that move goods:

```
available = batch_items.qty
          - refunds(type = purchase)    returned to the provider
          - order_items.qty             sold to a client
          + refunds(type = sale)        returned by a client
```

One expression, `StockService::stockPerBatchItem()`, backs ordering, refund limits and both
reports, so the numbers cannot drift apart. It also takes an optional date, which is what
makes the historical storage report possible — a running counter only knows today's value,
so answering "what was on the shelf on 5 September" would need the event history anyway.

**A product's provider** is found by walking `categories.parent_id` up to the root and
reading its `provider_id`. Keeping it only on the root means a child cannot contradict it.
Buying a product from a provider that does not supply its root category is rejected.

**Three prices, on purpose.** `batch_items.purchase_price` differs per batch, which is what
makes per-batch profit meaningful. `order_items.sale_price` is copied at the moment of sale,
so changing a product's price later does not rewrite past orders or past profit.

**One refunds table.** Both directions carry the same data — how many units, when. `type`
says which side it belongs to and fills exactly one of `batch_item_id` / `order_item_id`.
Two tables would have duplicated the columns and both report queries.

**Order lines point at batches, not products.** `batch_items` already holds both, so one
foreign key covers it. This is also why ordering 120 units against batches of 100 and 80
creates two order lines: they have different costs.

## Endpoints

All under `/api`; failures return standard Laravel 422 responses.

### `POST /purchases`

```json
{
  "provider_id": 1, "storage_id": 1, "purchased_at": "2026-09-01",
  "items": [
    { "product_id": 1, "qty": 100, "purchase_price": 40000 },
    { "product_id": 3, "qty": 50,  "purchase_price": 28000 }
  ]
}
```

`purchased_at` defaults to today.

### `POST /batches/{batch}/refunds`

```json
{ "refunded_at": "2026-09-05", "items": [ { "product_id": 3, "qty": 10 } ] }
```

Capped by what is still unsold in that batch line, so goods already sold to a client cannot
be sent back to the provider.

### `GET /products/available`

```json
{ "data": [
  { "id": 1, "name": "Ahmad Tea Earl Grey, 500g", "category_name": "Black Tea",
    "price": 62000, "qty": 180 }
] }
```

`qty` is summed across every batch and storage; products at zero are omitted.

### `POST /orders`

```json
{ "client_id": 1, "ordered_at": "2026-09-12", "products": [ { "id": 1, "qty": 120 } ] }
```

No `batch_id` is sent. Batches are picked by oldest `purchased_at` first, splitting one
ordered quantity across several batches when no single batch covers it. Ordering more than
is available fails and the whole order rolls back. Rows are locked for the transaction so
two concurrent orders cannot both take the last unit.

### `POST /orders/{order}/refunds`

```json
{ "refunded_at": "2026-09-14", "products": [ { "id": 1, "qty": 5 } ] }
```

Matched against that order's own lines. Returned goods go back to the storage of the batch
they came from and become available again.

### `GET /reports/storage-remaining?date=YYYY-MM-DD[&storage_id=]`

Quantities left per storage and product at the **end of that date** — a historical snapshot,
not current state.

### `GET /reports/batch-profit`

```
net_revenue  = sold minus what clients sent back
cost_of_sold = purchase price of those same units
profit       = net_revenue - cost_of_sold
```

Goods refunded to the provider drop out of the cost. Units still on the shelf are an asset,
not a loss, so they stay out of `profit` — otherwise a fresh batch would always show one;
they appear as `remaining_qty` against `net_purchase_cost` instead.

## Not included

No authentication, no CRUD for the reference tables (providers, products, categories,
storages, clients are seeded), no automated tests, and the reports are not paginated.

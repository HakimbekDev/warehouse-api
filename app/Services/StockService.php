<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock is derived, never stored. Four events move goods:
 *
 *   + batch_items.qty     arrived from the provider
 *   - purchase refunds    sent back to the provider
 *   - order_items.qty     sold to a client
 *   + sale refunds        returned by a client
 *
 * A counter column could not answer "what was on the shelf on 5 September",
 * which the storage report needs, so the events are the only source of truth.
 */
class StockService
{
    /**
     * Units of each batch line still in storage. With $date, only events up to
     * that date count.
     *
     * The three sides are aggregated in subqueries before being joined: joining
     * refunds and order_items directly would multiply the rows and inflate the sums.
     */
    public function stockPerBatchItem(?string $date = null): Builder
    {
        $purchaseRefunded = DB::table('refunds')
            ->selectRaw('batch_item_id, SUM(qty) AS qty')
            ->where('type', 'purchase')
            ->when($date, fn ($q) => $q->whereDate('refunded_at', '<=', $date))
            ->groupBy('batch_item_id');

        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw('order_items.batch_item_id AS batch_item_id, SUM(order_items.qty) AS qty')
            ->when($date, fn ($q) => $q->whereDate('orders.ordered_at', '<=', $date))
            ->groupBy('order_items.batch_item_id');

        $saleRefunded = DB::table('refunds')
            ->join('order_items', 'order_items.id', '=', 'refunds.order_item_id')
            ->selectRaw('order_items.batch_item_id AS batch_item_id, SUM(refunds.qty) AS qty')
            ->where('refunds.type', 'sale')
            ->when($date, fn ($q) => $q->whereDate('refunds.refunded_at', '<=', $date))
            ->groupBy('order_items.batch_item_id');

        return DB::table('batch_items as bi')
            ->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->leftJoinSub($purchaseRefunded, 'pr', 'pr.batch_item_id', '=', 'bi.id')
            ->leftJoinSub($sold, 's', 's.batch_item_id', '=', 'bi.id')
            ->leftJoinSub($saleRefunded, 'sr', 'sr.batch_item_id', '=', 'bi.id')
            ->when($date, fn ($q) => $q->whereDate('b.purchased_at', '<=', $date))
            ->select([
                'bi.id',
                'bi.batch_id',
                'bi.product_id',
                'bi.purchase_price',
                'b.storage_id',
                'b.purchased_at',
                DB::raw('(bi.qty - COALESCE(pr.qty, 0) - COALESCE(s.qty, 0) + COALESCE(sr.qty, 0)) AS available_qty'),
            ]);
    }

    /** Orderable products, quantity summed across every batch and storage. */
    public function availableProducts(): Collection
    {
        return DB::query()
            ->fromSub($this->stockPerBatchItem(), 'stock')
            ->join('products as p', 'p.id', '=', 'stock.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('p.id', 'p.name', 'c.name', 'p.price')
            ->havingRaw('SUM(stock.available_qty) > 0')
            ->orderBy('p.name')
            ->select([
                'p.id',
                'p.name',
                'c.name AS category_name',
                'p.price',
                DB::raw('SUM(stock.available_qty) AS qty'),
            ])
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'category_name' => $row->category_name,
                'price' => (float) $row->price,
                'qty' => (int) $row->qty,
            ]);
    }

    /**
     * Batch lines holding this product, oldest batch first. Ties are broken by id
     * so the order is deterministic when two batches share a date.
     */
    public function fifoQueue(int $productId): Collection
    {
        return DB::query()
            ->fromSub($this->stockPerBatchItem(), 'stock')
            ->where('stock.product_id', $productId)
            ->where('stock.available_qty', '>', 0)
            ->orderBy('stock.purchased_at')
            ->orderBy('stock.id')
            ->get();
    }

    /** The ceiling for a refund back to the provider. */
    public function availableQtyForBatchItem(int $batchItemId): int
    {
        $row = DB::query()
            ->fromSub($this->stockPerBatchItem(), 'stock')
            ->where('stock.id', $batchItemId)
            ->first();

        return $row ? (int) $row->available_qty : 0;
    }

    /**
     * Locks the batch lines of these products so two concurrent orders cannot
     * both hand out the last unit.
     *
     * @param  array<int, int>  $productIds
     */
    public function lockProductStock(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        DB::table('batch_items')
            ->whereIn('product_id', $productIds)
            ->lockForUpdate()
            ->get();
    }
}

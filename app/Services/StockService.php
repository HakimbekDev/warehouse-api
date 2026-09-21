<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock is the sum of stock_movements. Every arrival, sale and refund is one
 * signed row, so there is one rule for everything here: SUM the movements.
 */
class StockService
{
    /**
     * Movements joined to the batch line they belong to, optionally cut off at a
     * date. Callers add their own GROUP BY — and the batches join only when they
     * need it — which keeps every stock query a single flat aggregate rather
     * than an aggregate over a subquery.
     */
    public function movements(?string $date = null): Builder
    {
        return DB::table('stock_movements as m')
            ->join('batch_items as bi', 'bi.id', '=', 'm.batch_item_id')
            ->when($date, fn ($q) => $q->whereDate('m.moved_at', '<=', $date));
    }

    /** Orderable products, quantity summed across every batch and storage. */
    public function availableProducts(): Collection
    {
        return $this->movements()
            ->join('products as p', 'p.id', '=', 'bi.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->groupBy('p.id', 'p.name', 'c.name', 'p.price')
            ->havingRaw('SUM(m.qty) > 0')
            ->orderBy('p.name')
            ->select([
                'p.id',
                'p.name',
                'c.name AS category_name',
                'p.price',
                DB::raw('SUM(m.qty) AS qty'),
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
     * Batch lines still holding any of these products, oldest batch first.
     *
     * Takes every product of an order at once, so a fifty-product order costs
     * one query here, not fifty. The caller groups the result by product_id.
     */
    public function fifoQueueFor(array $productIds): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return $this->movements()
            ->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->whereIn('bi.product_id', $productIds)
            ->groupBy('bi.id', 'bi.product_id', 'b.purchased_at')
            ->havingRaw('SUM(m.qty) > 0')
            ->orderBy('b.purchased_at')
            ->orderBy('bi.id')
            ->select([
                'bi.id',
                'bi.product_id',
                DB::raw('SUM(m.qty) AS available_qty'),
            ])
            ->get();
    }

    /**
     * Units left per batch line — the ceiling for a refund back to the provider.
     * Keyed by batch_item_id, one query for the whole refund.
     */
    public function availableQtyForBatchItems(array $batchItemIds): Collection
    {
        if ($batchItemIds === []) {
            return collect();
        }

        return DB::table('stock_movements')
            ->whereIn('batch_item_id', $batchItemIds)
            ->groupBy('batch_item_id')
            ->pluck(DB::raw('SUM(qty)'), 'batch_item_id')
            ->map(fn ($qty) => (int) $qty);
    }

    /**
     * Locks the batch lines of these products so two concurrent orders cannot
     * both hand out the last unit.
     */
    public function lockProductStock(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        DB::table('batch_items')
            ->whereIn('product_id', $productIds)
            ->select('id')
            ->lockForUpdate()
            ->get();
    }
}

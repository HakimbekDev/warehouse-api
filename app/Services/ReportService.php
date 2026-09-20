<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * What each storage held at the end of the given date. Only events dated on
     * or before $date count, so this is a historical snapshot, not current state.
     */
    public function remainingStock(string $date, ?int $storageId = null): Collection
    {
        return DB::query()
            ->fromSub($this->stock->stockPerBatchItem($date), 'stock')
            ->join('products as p', 'p.id', '=', 'stock.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->join('storages as st', 'st.id', '=', 'stock.storage_id')
            ->when($storageId, fn ($q) => $q->where('stock.storage_id', $storageId))
            ->groupBy('st.id', 'st.name', 'p.id', 'p.name', 'c.name')
            ->havingRaw('SUM(stock.available_qty) <> 0')
            ->orderBy('st.name')
            ->orderBy('p.name')
            ->select([
                'st.id AS storage_id',
                'st.name AS storage_name',
                'p.id AS product_id',
                'p.name AS product_name',
                'c.name AS category_name',
                DB::raw('SUM(stock.available_qty) AS qty'),
            ])
            ->get()
            ->map(fn ($row) => [
                'storage_id' => (int) $row->storage_id,
                'storage_name' => $row->storage_name,
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'category_name' => $row->category_name,
                'qty' => (int) $row->qty,
            ]);
    }

    /**
     * Profit per batch, on a realised basis:
     *
     *   net_revenue  = sold minus what clients sent back
     *   cost_of_sold = purchase price of those same units
     *   profit       = net_revenue - cost_of_sold
     *
     * Units still on the shelf are an asset, not a loss, so they stay out of the
     * profit line — otherwise a fresh batch would always show one. Goods refunded
     * to the provider drop out of the cost, since we did not pay for them.
     *
     * Each line is netted against its own refunds before being multiplied, so the
     * sale price and purchase price applied are always the ones that line used.
     */
    public function batchProfit(): Collection
    {
        $purchaseRefunded = DB::table('refunds')
            ->selectRaw('batch_item_id, SUM(qty) AS qty')
            ->where('type', 'purchase')
            ->groupBy('batch_item_id');

        $purchases = DB::table('batch_items as bi')
            ->leftJoinSub($purchaseRefunded, 'pr', 'pr.batch_item_id', '=', 'bi.id')
            ->groupBy('bi.batch_id')
            ->select([
                'bi.batch_id',
                DB::raw('SUM(bi.qty) AS purchased_qty'),
                DB::raw('SUM(COALESCE(pr.qty, 0)) AS provider_refunded_qty'),
                DB::raw('SUM((bi.qty - COALESCE(pr.qty, 0)) * bi.purchase_price) AS net_purchase_cost'),
            ]);

        $saleRefunded = DB::table('refunds')
            ->selectRaw('order_item_id, SUM(qty) AS qty')
            ->where('type', 'sale')
            ->groupBy('order_item_id');

        $sales = DB::table('order_items as oi')
            ->join('batch_items as bi', 'bi.id', '=', 'oi.batch_item_id')
            ->leftJoinSub($saleRefunded, 'sr', 'sr.order_item_id', '=', 'oi.id')
            ->groupBy('bi.batch_id')
            ->select([
                'bi.batch_id',
                DB::raw('SUM(oi.qty) AS sold_qty'),
                DB::raw('SUM(COALESCE(sr.qty, 0)) AS client_refunded_qty'),
                DB::raw('SUM((oi.qty - COALESCE(sr.qty, 0)) * oi.sale_price) AS net_revenue'),
                DB::raw('SUM((oi.qty - COALESCE(sr.qty, 0)) * bi.purchase_price) AS cost_of_sold'),
            ]);

        return DB::table('batches as b')
            ->join('providers as pv', 'pv.id', '=', 'b.provider_id')
            ->leftJoinSub($purchases, 'p', 'p.batch_id', '=', 'b.id')
            ->leftJoinSub($sales, 's', 's.batch_id', '=', 'b.id')
            ->orderBy('b.purchased_at')
            ->orderBy('b.id')
            ->select([
                'b.id',
                'b.purchased_at',
                'pv.name AS provider_name',
                DB::raw('COALESCE(p.purchased_qty, 0) AS purchased_qty'),
                DB::raw('COALESCE(p.provider_refunded_qty, 0) AS provider_refunded_qty'),
                DB::raw('COALESCE(p.net_purchase_cost, 0) AS net_purchase_cost'),
                DB::raw('COALESCE(s.sold_qty, 0) AS sold_qty'),
                DB::raw('COALESCE(s.client_refunded_qty, 0) AS client_refunded_qty'),
                DB::raw('COALESCE(s.net_revenue, 0) AS net_revenue'),
                DB::raw('COALESCE(s.cost_of_sold, 0) AS cost_of_sold'),
            ])
            ->get()
            ->map(fn ($row) => [
                'batch_id' => (int) $row->id,
                'purchased_at' => $row->purchased_at,
                'provider_name' => $row->provider_name,

                'purchased_qty' => (int) $row->purchased_qty,
                'provider_refunded_qty' => (int) $row->provider_refunded_qty,
                'sold_qty' => (int) $row->sold_qty,
                'client_refunded_qty' => (int) $row->client_refunded_qty,
                'remaining_qty' => (int) $row->purchased_qty
                    - (int) $row->provider_refunded_qty
                    - (int) $row->sold_qty
                    + (int) $row->client_refunded_qty,

                'net_purchase_cost' => round((float) $row->net_purchase_cost, 2),
                'net_revenue' => round((float) $row->net_revenue, 2),
                'cost_of_sold' => round((float) $row->cost_of_sold, 2),
                'profit' => round((float) $row->net_revenue - (float) $row->cost_of_sold, 2),
            ]);
    }
}

<?php

namespace App\Services;

use App\Models\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * What each storage held at the end of the given date. Only movements dated
     * on or before $date count, so this is a historical snapshot.
     */
    public function remainingStock(string $date, ?int $storageId = null): Collection
    {
        return $this->stock->movements($date)
            ->join('batches as b', 'b.id', '=', 'bi.batch_id')
            ->join('storages as st', 'st.id', '=', 'b.storage_id')
            ->join('products as p', 'p.id', '=', 'bi.product_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->when($storageId, fn ($q) => $q->where('b.storage_id', $storageId))
            ->groupBy('st.id', 'st.name', 'p.id', 'p.name', 'c.name')
            ->havingRaw('SUM(m.qty) <> 0')
            ->orderBy('st.name')
            ->orderBy('p.name')
            ->select([
                'st.id AS storage_id',
                'st.name AS storage_name',
                'p.id AS product_id',
                'p.name AS product_name',
                'c.name AS category_name',
                DB::raw('SUM(m.qty) AS qty'),
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
     * Because movements are signed, refunds need no special case — a purchase
     * refund is a negative arrival and a sale refund a negative sale, so adding
     * every row up gives the net figures directly. Each row carries the price it
     * used, so nothing has to be joined to find it.
     *
     * Units still on the shelf are an asset, not a loss, so they stay out of the
     * profit line; otherwise a fresh batch would always show one.
     *
     * Eager loading keeps this to a handful of queries however many batches exist.
     */
    public function batchProfit(): Collection
    {
        return Batch::with(['provider', 'items.movements'])
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get()
            ->map(function (Batch $batch) {
                $purchasedQty = $providerRefundedQty = 0;
                $soldQty = $clientRefundedQty = 0;
                $netPurchaseCost = $netRevenue = $costOfSold = 0.0;

                foreach ($batch->items as $item) {
                    $cost = (float) $item->purchase_price;

                    foreach ($item->movements as $movement) {
                        $qty = $movement->qty;

                        if ($movement->isSale()) {
                            // sale is negative, sale_refund positive
                            $netRevenue -= $qty * (float) $movement->unit_price;
                            $costOfSold -= $qty * $cost;
                            $qty < 0 ? $soldQty -= $qty : $clientRefundedQty += $qty;
                        } else {
                            // purchase is positive, purchase_refund negative
                            $netPurchaseCost += $qty * $cost;
                            $qty > 0 ? $purchasedQty += $qty : $providerRefundedQty -= $qty;
                        }
                    }
                }

                return [
                    'batch_id' => $batch->id,
                    'purchased_at' => $batch->purchased_at->toDateString(),
                    'provider_name' => $batch->provider->name,

                    'purchased_qty' => $purchasedQty,
                    'provider_refunded_qty' => $providerRefundedQty,
                    'sold_qty' => $soldQty,
                    'client_refunded_qty' => $clientRefundedQty,
                    'remaining_qty' => $purchasedQty - $providerRefundedQty - $soldQty + $clientRefundedQty,

                    'net_purchase_cost' => round($netPurchaseCost, 2),
                    'net_revenue' => round($netRevenue, 2),
                    'cost_of_sold' => round($costOfSold, 2),
                    'profit' => round($netRevenue - $costOfSold, 2),
                ];
            });
    }
}

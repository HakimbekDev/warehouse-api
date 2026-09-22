<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * Create a client order. The caller sends products and quantities only; the
     * batch behind each line is chosen here, oldest stock first.
     *
     * The order's lines are the sale rows of the ledger, so they are written
     * once, in bulk — the cost is a fixed handful of queries whatever the order
     * contains.
     */
    public function create(int $clientId, array $products, ?string $orderedAt = null): Order
    {
        $productIds = array_column($products, 'id');
        $orderedAt ??= now()->toDateString();

        return DB::transaction(function () use ($clientId, $products, $productIds, $orderedAt) {
            $this->stock->lockProductStock($productIds);

            $ordered = Product::findMany($productIds)->keyBy('id');
            $queues = $this->stock->fifoQueueFor($productIds)->groupBy('product_id');

            $order = Order::create([
                'client_id' => $clientId,
                'ordered_at' => $orderedAt,
            ]);

            $rows = [];

            foreach ($products as $line) {
                $rows = array_merge($rows, $this->allocate(
                    $queues->get($line['id'], collect()),
                    $ordered[$line['id']],
                    (int) $line['qty'],
                    $order->id,
                    $orderedAt,
                ));
            }

            StockMovement::insert($rows);

            return $order->load('movements.batchItem.product', 'movements.batchItem.batch', 'client');
        });
    }

    /**
     * Spread one ordered quantity over the oldest batches holding the product,
     * as one sale row per batch drawn from.
     *
     * Pure: it reads the queue it was handed and returns rows to insert, so no
     * query runs per product or per batch line. Ordering 120 against batches of
     * 100 and 80 produces two rows — they have different costs, which per-batch
     * profit depends on.
     */
    private function allocate(
        Collection $queue,
        Product $product,
        int $qty,
        int $orderId,
        string $orderedAt,
    ): array {
        $remaining = $qty;
        $rows = [];
        $now = now();

        foreach ($queue as $batchLine) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batchLine->available_qty);

            $rows[] = [
                'batch_item_id' => $batchLine->id,
                'order_id' => $orderId,
                'type' => StockMovement::SALE,
                'qty' => -$take,
                'unit_price' => $product->price,
                'moved_at' => $orderedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'products' => "Not enough stock for \"{$product->name}\": "
                    . ($qty - $remaining) . " available, {$qty} requested.",
            ]);
        }

        return $rows;
    }

    /**
     * Take goods back from a client. The goods return to the storage of the batch
     * they came from, at the price that order sold them for.
     */
    public function refund(Order $order, array $products, string $refundedAt): array
    {
        return DB::transaction(function () use ($order, $products, $refundedAt) {
            DB::table('stock_movements')
                ->where('order_id', $order->id)
                ->select('id')
                ->lockForUpdate()
                ->get();

            $lines = $this->outstandingLines($order)->groupBy('product_id');

            $rows = [];
            $now = now();

            foreach ($products as $line) {
                $remaining = (int) $line['qty'];

                foreach ($lines->get((int) $line['id'], collect()) as $outstanding) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, (int) $outstanding->refundable);

                    $rows[] = [
                        'batch_item_id' => $outstanding->batch_item_id,
                        'order_id' => $order->id,
                        'type' => StockMovement::SALE_REFUND,
                        'qty' => $take,
                        'unit_price' => $outstanding->unit_price,
                        'moved_at' => $refundedAt,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $remaining -= $take;
                }

                if ($remaining > 0) {
                    throw ValidationException::withMessages([
                        'products' => "Cannot refund {$line['qty']} of product {$line['id']}: "
                            . "order {$order->id} has fewer un-refunded units than that.",
                    ]);
                }
            }

            StockMovement::insert($rows);

            return $rows;
        });
    }

    /**
     * What each line of this order still has out with the client.
     *
     * A sale row is negative and its refunds positive, so what is left out is
     * minus their sum; lines already fully returned drop out on their own.
     */
    private function outstandingLines(Order $order): Collection
    {
        return DB::table('stock_movements as m')
            ->join('batch_items as bi', 'bi.id', '=', 'm.batch_item_id')
            ->where('m.order_id', $order->id)
            ->groupBy('m.batch_item_id', 'bi.product_id')
            ->havingRaw('SUM(m.qty) < 0')
            ->orderBy('m.batch_item_id')
            ->select([
                'm.batch_item_id',
                'bi.product_id',
                DB::raw('-SUM(m.qty) AS refundable'),
                DB::raw('MAX(m.unit_price) AS unit_price'),
            ])
            ->get();
    }
}

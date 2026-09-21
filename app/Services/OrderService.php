<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
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
     * Everything is fetched and written in bulk, so the cost is a fixed handful
     * of queries whatever the order contains.
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

            $lines = [];

            foreach ($products as $line) {
                $lines = array_merge($lines, $this->allocate(
                    $queues->get($line['id'], collect()),
                    $ordered[$line['id']],
                    (int) $line['qty'],
                    $order->id,
                ));
            }

            OrderItem::insert($lines);

            $this->recordSales($order, $orderedAt);

            return $order->load('items.batchItem.product', 'items.batchItem.batch', 'client');
        });
    }

    /**
     * Spread one ordered quantity over the oldest batches holding the product.
     *
     * Pure: it reads the queue it was handed and returns rows to insert, so no
     * query runs per product or per batch line. Ordering 120 against batches of
     * 100 and 80 produces two rows — they have different costs, which per-batch
     * profit depends on.
     */
    private function allocate(Collection $queue, Product $product, int $qty, int $orderId): array
    {
        $remaining = $qty;
        $rows = [];
        $now = now();

        foreach ($queue as $batchLine) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batchLine->available_qty);

            $rows[] = [
                'order_id' => $orderId,
                'batch_item_id' => $batchLine->id,
                'qty' => $take,
                'sale_price' => $product->price,
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

    /** One movement row per order line, taking the goods out of storage. */
    private function recordSales(Order $order, string $orderedAt): void
    {
        $now = now();

        $movements = $order->items()->get()->map(fn (OrderItem $item) => [
            'batch_item_id' => $item->batch_item_id,
            'order_item_id' => $item->id,
            'type' => StockMovement::SALE,
            'qty' => -$item->qty,
            'moved_at' => $orderedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        StockMovement::insert($movements);
    }

    /**
     * Take goods back from a client, matching quantities against that order's own
     * lines. The goods return to the storage of the batch they came from.
     */
    public function refund(Order $order, array $products, string $refundedAt): array
    {
        return DB::transaction(function () use ($order, $products, $refundedAt) {
            $items = $order->items()
                ->with('batchItem')
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            // How many units of each line are still un-refunded.
            $refundable = $this->refundableQtyPerItem($items->pluck('id')->all());

            $rows = [];
            $now = now();

            foreach ($products as $line) {
                $remaining = (int) $line['qty'];

                foreach ($items->where('batchItem.product_id', (int) $line['id']) as $item) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, $refundable[$item->id] ?? 0);

                    if ($take <= 0) {
                        continue;
                    }

                    $rows[] = [
                        'batch_item_id' => $item->batch_item_id,
                        'order_item_id' => $item->id,
                        'type' => StockMovement::SALE_REFUND,
                        'qty' => $take,
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
     * A sale row is negative and its refunds positive, so what is still out with
     * the client is simply minus their sum. One query for the whole order.
     */
    private function refundableQtyPerItem(array $orderItemIds): Collection
    {
        return DB::table('stock_movements')
            ->whereIn('order_item_id', $orderItemIds)
            ->groupBy('order_item_id')
            ->pluck(DB::raw('-SUM(qty)'), 'order_item_id')
            ->map(fn ($qty) => (int) $qty);
    }
}

<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private readonly StockService $stock)
    {
    }

    /**
     * Create a client order. The caller sends products and quantities only; the
     * batch behind each line is chosen here.
     */
    public function create(int $clientId, array $products, ?string $orderedAt = null): Order
    {
        return DB::transaction(function () use ($clientId, $products, $orderedAt) {
            $this->stock->lockProductStock(array_column($products, 'id'));

            $order = Order::create([
                'client_id' => $clientId,
                'ordered_at' => $orderedAt ?? now()->toDateString(),
            ]);

            foreach ($products as $line) {
                $this->allocate($order, (int) $line['id'], (int) $line['qty']);
            }

            return $order->load('items.batchItem.product', 'items.batchItem.batch', 'client');
        });
    }

    /**
     * Spread one ordered quantity over the oldest batches holding the product,
     * writing one order line per batch drawn from. Ordering 120 against batches
     * of 100 and 80 therefore produces two lines — they have different costs,
     * which per-batch profit depends on.
     */
    private function allocate(Order $order, int $productId, int $qty): void
    {
        $product = Product::find($productId);
        $remaining = $qty;

        foreach ($this->stock->fifoQueue($productId) as $batchLine) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batchLine->available_qty);

            $order->items()->create([
                'batch_item_id' => $batchLine->id,
                'qty' => $take,
                'sale_price' => $product->price,
            ]);

            $remaining -= $take;
        }

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'products' => "Not enough stock for \"{$product->name}\": "
                    . ($qty - $remaining) . " available, {$qty} requested.",
            ]);
        }
    }

    /**
     * Take goods back from a client, matching quantities against that order's own
     * lines. The goods return to the storage of the batch they came from.
     */
    public function refund(Order $order, array $products, string $refundedAt): array
    {
        return DB::transaction(function () use ($order, $products, $refundedAt) {
            $created = [];

            foreach ($products as $line) {
                $remaining = (int) $line['qty'];

                $orderItems = $order->items()
                    ->whereHas('batchItem', fn ($q) => $q->where('product_id', $line['id']))
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                foreach ($orderItems as $orderItem) {
                    if ($remaining <= 0) {
                        break;
                    }

                    $take = min($remaining, $orderItem->refundableQty());

                    if ($take <= 0) {
                        continue;
                    }

                    $created[] = Refund::create([
                        'type' => Refund::TYPE_SALE,
                        'order_item_id' => $orderItem->id,
                        'qty' => $take,
                        'refunded_at' => $refundedAt,
                    ]);

                    $remaining -= $take;
                }

                if ($remaining > 0) {
                    throw ValidationException::withMessages([
                        'products' => "Cannot refund {$line['qty']} of product {$line['id']}: "
                            . "order {$order->id} has fewer un-refunded units than that.",
                    ]);
                }
            }

            return $created;
        });
    }
}

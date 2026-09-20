<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RefundOrderRequest;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders)
    {
    }

    /** Sell products to a client. Batches are assigned by the backend, oldest first. */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create(
            (int) $request->validated('client_id'),
            $request->validated('products'),
            $request->validated('ordered_at'),
        );

        return response()->json([
            'message' => 'Order created.',
            'data' => [
                'order_id' => $order->id,
                'client' => $order->client->name,
                'ordered_at' => $order->ordered_at->toDateString(),
                'items' => $order->items->map(fn ($item) => [
                    'order_item_id' => $item->id,
                    'product_id' => $item->batchItem->product_id,
                    'product_name' => $item->batchItem->product->name,
                    'batch_id' => $item->batchItem->batch_id,
                    'purchased_at' => $item->batchItem->batch->purchased_at->toDateString(),
                    'qty' => $item->qty,
                    'sale_price' => (float) $item->sale_price,
                ]),
                'total' => round($order->items->sum(fn ($i) => $i->qty * (float) $i->sale_price), 2),
            ],
        ], 201);
    }

    /** Take sold goods back from a client, fully or partially. */
    public function refund(RefundOrderRequest $request, Order $order): JsonResponse
    {
        $refunds = $this->orders->refund(
            $order,
            $request->validated('products'),
            $request->validated('refunded_at') ?? now()->toDateString(),
        );

        return response()->json([
            'message' => 'Refund registered; the goods returned to storage.',
            'data' => [
                'order_id' => $order->id,
                'refunds' => array_map(fn ($refund) => [
                    'refund_id' => $refund->id,
                    'order_item_id' => $refund->order_item_id,
                    'qty' => $refund->qty,
                    'refunded_at' => $refund->refunded_at->toDateString(),
                ], $refunds),
            ],
        ], 201);
    }
}

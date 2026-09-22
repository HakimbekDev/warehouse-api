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
                'items' => $order->movements->map(fn ($sale) => [
                    'product_id' => $sale->batchItem->product_id,
                    'product_name' => $sale->batchItem->product->name,
                    'batch_id' => $sale->batchItem->batch_id,
                    'purchased_at' => $sale->batchItem->batch->purchased_at->toDateString(),
                    'qty' => -$sale->qty,
                    'sale_price' => (float) $sale->unit_price,
                ]),
                'total' => round($order->movements->sum(fn ($s) => -$s->qty * (float) $s->unit_price), 2),
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
                'refunds' => array_map(fn ($row) => [
                    'batch_item_id' => $row['batch_item_id'],
                    'qty' => $row['qty'],
                    'refunded_at' => $row['moved_at'],
                ], $refunds),
            ],
        ], 201);
    }
}

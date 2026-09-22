<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ledger. Every change of stock is one signed row, so the stock of
        // anything is the SUM of its rows, and the stock on a given day is the
        // SUM of the rows up to that day.
        //
        //   purchase         +qty   arrived from the provider
        //   purchase_refund  -qty   sent back to the provider
        //   sale             -qty   sold to a client
        //   sale_refund      +qty   returned by a client
        //
        // Refunds are movements, and so are order lines: a sale row is the line.
        // That is why there is no refunds table and no order_items table.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            // Which batch line the goods belong to — the lot FIFO draws from.
            $table->foreignId('batch_item_id')->constrained()->cascadeOnDelete();

            // Set on sale and sale_refund rows only.
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();

            $table->enum('type', ['purchase', 'purchase_refund', 'sale', 'sale_refund']);
            $table->integer('qty');

            // The purchase price on purchase rows, the sale price on sale rows,
            // captured here so later price edits cannot rewrite history.
            $table->decimal('unit_price', 12, 2);

            $table->date('moved_at');
            $table->timestamps();

            $table->index(['batch_item_id', 'moved_at']);
            $table->index(['order_id', 'batch_item_id']);

            // The storage report filters on the date alone.
            $table->index('moved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every change of stock is one row with a signed quantity, so the stock
        // of anything is the SUM of its rows, and the stock on a given day is
        // the SUM of the rows up to that day.
        //
        //   purchase         +qty   arrived from the provider
        //   purchase_refund  -qty   sent back to the provider
        //   sale             -qty   sold to a client
        //   sale_refund      +qty   returned by a client
        //
        // Refunds are movements too, which is why there is no separate table.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_item_id')->constrained()->cascadeOnDelete();

            // Set on sale and sale_refund rows; it carries the price the sale used.
            $table->foreignId('order_item_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->enum('type', ['purchase', 'purchase_refund', 'sale', 'sale_refund']);
            $table->integer('qty');
            $table->date('moved_at');
            $table->timestamps();

            $table->index(['batch_item_id', 'moved_at']);
            $table->index('order_item_id');

            // The storage report filters on the date alone, which the composite
            // index above cannot serve.
            $table->index('moved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

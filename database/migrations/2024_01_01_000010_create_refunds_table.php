<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One table for both refund directions. `type` says which side it belongs to
        // and exactly one of the two foreign keys is filled in.
        //   purchase -> we send goods back to the provider (batch_item_id)
        //   sale     -> a client sends goods back to us   (order_item_id)
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['purchase', 'sale']);

            $table->foreignId('batch_item_id')->nullable()
                ->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->unsignedInteger('qty');
            $table->date('refunded_at');
            $table->timestamps();

            $table->index(['type', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};

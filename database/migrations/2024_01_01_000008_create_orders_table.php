<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->date('ordered_at');
            $table->timestamps();

            $table->index('ordered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

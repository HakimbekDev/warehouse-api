<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->restrictOnDelete();

            // A batch is delivered into one storage; stock location is derived from here.
            $table->foreignId('storage_id')->constrained()->restrictOnDelete();

            // Drives FIFO picking and the date-bounded stock report.
            $table->date('purchased_at');

            $table->timestamps();

            $table->index('purchased_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->id();
            $table->string('order_id')->unique();
            $table->string('payment_method', 20);
            $table->decimal('cash_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            // PayMongo payment intent id; unique so one QR payment can only settle one sale.
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};

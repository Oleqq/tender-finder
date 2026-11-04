<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbank_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 64)->unique();
            $table->string('terminal_key', 64);
            $table->string('payment_id', 20)->index();
            $table->string('order_id', 50)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('payment_status', 40);
            $table->boolean('success');
            $table->string('error_code', 20);
            $table->string('state', 20)->default('received')->index();
            $table->uuid('request_id')->nullable();
            $table->timestamp('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbank_notifications');
    }
};

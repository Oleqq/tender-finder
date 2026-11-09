<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbank_payment_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained('subscription_orders');
            $table->string('terminal_key', 64);
            $table->string('payment_id', 20)->nullable();
            $table->unsignedInteger('amount_minor');
            $table->text('request_payload');
            $table->text('payment_url')->nullable();
            $table->string('state', 24)->default('prepared')->index();
            $table->string('bank_status', 40)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestampTz('init_started_at')->nullable();
            $table->timestampTz('next_check_at')->nullable()->index();
            $table->uuid('check_token')->nullable();
            $table->unsignedInteger('check_count')->default(0);
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['terminal_key', 'payment_id']);
        });
        Schema::table('tbank_notifications', function (Blueprint $table): void {
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable()->index();
            $table->timestampTz('processed_at')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->uuid('processing_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tbank_notifications', fn (Blueprint $table) => $table->dropIndex(['next_retry_at']));
        Schema::table('tbank_notifications', fn (Blueprint $table) => $table->dropColumn(['attempts', 'next_retry_at', 'processed_at', 'failure_code', 'processing_token']));
        Schema::dropIfExists('tbank_payment_attempts');
    }
};

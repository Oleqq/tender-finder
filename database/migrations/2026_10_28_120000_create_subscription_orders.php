<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_demo_guest')->default(false);
        });
        Schema::create('subscription_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->string('plan_code');
            $table->unsignedSmallInteger('duration_months');
            $table->unsignedInteger('amount_minor');
            $table->char('currency', 3);
            $table->json('terms');
            $table->string('provider');
            $table->boolean('is_demo')->default(true);
            $table->string('status');
            $table->uuid('idempotency_key');
            $table->foreignId('subscription_id')->nullable()->unique()->constrained();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
        });
        Schema::create('subscription_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('order_id')->constrained('subscription_orders');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_order_events');
        Schema::dropIfExists('subscription_orders');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_demo_guest'));
    }
};

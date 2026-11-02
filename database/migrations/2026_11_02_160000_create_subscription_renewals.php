<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_renewals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('plan_code', 32);
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('RUB');
            $table->string('state', 16)->default('active');
            $table->string('consent_version', 100);
            $table->timestamp('consented_at');
            $table->timestamp('next_charge_at');
            $table->timestamp('notice_due_at')->index();
            $table->string('notice_status', 32)->default('pending');
            $table->timestamp('notice_sent_at')->nullable();
            $table->timestamp('charge_not_before')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure_code', 100)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
        Schema::create('subscription_renewal_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('renewal_id')->constrained('subscription_renewals')->cascadeOnDelete();
            $table->string('action', 32);
            $table->json('snapshot');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_renewal_events');
        Schema::dropIfExists('subscription_renewals');
    }
};

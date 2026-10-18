<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('telegram_initial_charge_id', 128)->nullable()->unique();
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('renewal_failed_at')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('invoice_expires_at')->nullable()->index();
            $table->timestamp('period_ends_at')->nullable();
        });

        Schema::table('telegram_updates', function (Blueprint $table) {
            // Only the fields needed to retry a payment event are retained.
            $table->json('payment_payload')->nullable();
            $table->unsignedTinyInteger('payment_attempts')->default(0);
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->string('status', 32);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::table('telegram_updates', fn (Blueprint $table) => $table->dropColumn(['payment_payload', 'payment_attempts']));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn(['invoice_expires_at', 'period_ends_at']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropUnique(['telegram_initial_charge_id']);
            $table->dropColumn(['telegram_initial_charge_id', 'auto_renew', 'renewal_failed_at']);
        });
    }
};

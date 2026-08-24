<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('telegram_id')->nullable()->unique()->after('id');
            $table->string('telegram_username')->nullable()->after('telegram_id');
            $table->string('telegram_first_name')->nullable()->after('telegram_username');
            $table->string('telegram_last_name')->nullable()->after('telegram_first_name');
            $table->string('telegram_language_code', 16)->nullable()->after('telegram_last_name');
            $table->timestamp('last_seen_at')->nullable()->index()->after('remember_token');
            $table->timestamp('trial_used_at')->nullable()->after('last_seen_at');
        });

        // Legacy roles never grant administration. A verified owner ID is the
        // only way the identity service assigns super_admin afterwards.
        DB::table('users')
            ->whereIn('role', ['user', 'admin'])
            ->update(['role' => 'subscriber']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('subscriber')->change();
        });

        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document', 32);
            $table->string('document_version', 100);
            $table->string('action', 16);
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'document', 'occurred_at']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true)->index();
            $table->json('limits');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('source', 32);
            $table->string('status', 32)->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'ends_at']);
        });

        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 100);
            $table->string('status', 32)->index();
            $table->unsignedInteger('value')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'code', 'status', 'ends_at']);
        });

        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_update_id')->unique();
            $table->string('type', 64)->nullable();
            $table->string('status', 32)->default('received')->index();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('consent_events');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_id']);
            $table->dropColumn([
                'telegram_id',
                'telegram_username',
                'telegram_first_name',
                'telegram_last_name',
                'telegram_language_code',
                'last_seen_at',
                'trial_used_at',
            ]);
        });
    }
};

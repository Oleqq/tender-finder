<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('keywords');
            $table->json('minus_keywords')->nullable();
            $table->string('region', 120)->nullable();
            $table->decimal('budget_min', 15, 2)->nullable();
            $table->decimal('budget_max', 15, 2)->nullable();
            $table->date('deadline_from')->nullable();
            $table->date('deadline_to')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->json('filters')->nullable();
            $table->timestamp('monitoring_started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('frozen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('source_feeds', function (Blueprint $table) {
            $table->id();
            $table->text('canonical_url');
            $table->string('url_hash', 64)->unique();
            $table->string('status', 32)->default('active')->index();
            $table->unsignedInteger('poll_interval_seconds')->default(600);
            $table->timestamp('next_poll_at')->nullable()->index();
            $table->timestamp('initialized_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamps();
        });

        Schema::create('source_feed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_feed_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 100)->nullable()->index();
            $table->text('canonical_url');
            $table->string('url_hash', 64);
            $table->text('title');
            $table->text('summary')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('content_hash', 64);
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['source_feed_id', 'url_hash']);
        });

        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->string('source', 64);
            $table->string('external_id', 100);
            $table->foreignId('source_feed_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reg_number', 32)->nullable()->index();
            $table->text('canonical_url');
            $table->string('canonical_url_hash', 64)->index();
            $table->text('title');
            $table->text('description')->nullable();
            $table->string('region', 120)->nullable();
            $table->decimal('budget_amount', 15, 2)->nullable();
            $table->string('currency', 3)->default('RUB');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });

        Schema::create('tender_query_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_query_id')->constrained()->cascadeOnDelete();
            $table->json('match_reasons');
            $table->timestamp('matched_at');
            $table->timestamps();

            $table->unique(['tender_id', 'search_query_id']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('search_query_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->string('status', 32)->default('queued')->index();
            $table->string('idempotency_key', 150)->unique();
            $table->json('payload')->nullable();
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'created_at']);
        });

        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_feed_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 64);
            $table->string('status', 32)->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('items_seen')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->timestamps();

            $table->index(['source_feed_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_runs');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('tender_query_matches');
        Schema::dropIfExists('tenders');
        Schema::dropIfExists('source_feed_items');
        Schema::dropIfExists('source_feeds');
        Schema::dropIfExists('search_queries');
    }
};

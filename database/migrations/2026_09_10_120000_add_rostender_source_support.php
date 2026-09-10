<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('source_feeds', function (Blueprint $table) {
            $table->string('source', 64)->default('eis_rss')->index()->after('id');
            $table->unsignedBigInteger('source_identifier')->nullable()->after('source');
            $table->unique(['source', 'source_identifier'], 'source_feeds_source_identifier_unique');
        });

        Schema::table('tenders', function (Blueprint $table) {
            $table->timestamp('external_updated_at')->nullable()->after('deadline_at');
            $table->timestamp('details_fetched_at')->nullable()->after('external_updated_at');
        });

        Schema::create('rostender_feed_search_query', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_feed_id')->constrained('source_feeds')->cascadeOnDelete();
            $table->foreignId('search_query_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['source_feed_id', 'search_query_id']);
        });

        Schema::create('rostender_api_usages', function (Blueprint $table) {
            $table->id();
            $table->date('usage_date')->unique();
            $table->unsignedInteger('successful_requests')->default(0);
            $table->unsignedInteger('in_flight_requests')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rostender_api_usages');
        Schema::dropIfExists('rostender_feed_search_query');

        Schema::table('tenders', function (Blueprint $table) {
            $table->dropColumn(['external_updated_at', 'details_fetched_at']);
        });

        Schema::table('source_feeds', function (Blueprint $table) {
            $table->dropUnique('source_feeds_source_identifier_unique');
            $table->dropColumn(['source', 'source_identifier']);
        });
    }
};

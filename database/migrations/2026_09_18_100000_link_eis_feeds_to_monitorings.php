<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_feed_search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_query_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['source_feed_id', 'search_query_id']);
            $table->index('search_query_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_feed_search_queries');
    }
};

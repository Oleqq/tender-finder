<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_mvp_search_snapshots', function (Blueprint $table) {
            $table->foreignId('search_query_id')
                ->nullable()
                ->after('user_id')
                ->constrained('search_queries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('local_mvp_search_snapshots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('search_query_id');
        });
    }
};

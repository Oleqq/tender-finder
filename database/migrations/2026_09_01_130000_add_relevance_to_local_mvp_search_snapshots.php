<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_mvp_search_snapshots', function (Blueprint $table) {
            $table->json('relevance')->nullable()->after('tender_ids');
        });
    }

    public function down(): void
    {
        Schema::table('local_mvp_search_snapshots', function (Blueprint $table) {
            $table->dropColumn('relevance');
        });
    }
};

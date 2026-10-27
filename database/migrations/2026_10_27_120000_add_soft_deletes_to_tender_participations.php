<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_participations', function (Blueprint $table) {
            $table->softDeletes();
            $table->index(['team_id', 'deleted_at']);
            $table->index(['user_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('tender_participations', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'deleted_at']);
            $table->dropIndex(['user_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });
    }
};

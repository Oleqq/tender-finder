<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasSingleSnapshotConstraint = Schema::hasIndex(
            'local_mvp_search_snapshots',
            ['user_id'],
            'unique',
        );
        $hasHistoryIndex = Schema::hasIndex(
            'local_mvp_search_snapshots',
            ['user_id', 'created_at'],
        );

        Schema::table('local_mvp_search_snapshots', function (Blueprint $table) use (
            $hasSingleSnapshotConstraint,
            $hasHistoryIndex,
        ) {
            if ($hasSingleSnapshotConstraint) {
                $table->dropUnique(['user_id']);
            }

            if (! $hasHistoryIndex) {
                $table->index(['user_id', 'created_at']);
            }
        });
    }

    public function down(): void
    {
        $hasHistoryIndex = Schema::hasIndex(
            'local_mvp_search_snapshots',
            ['user_id', 'created_at'],
        );
        $hasSingleSnapshotConstraint = Schema::hasIndex(
            'local_mvp_search_snapshots',
            ['user_id'],
            'unique',
        );

        Schema::table('local_mvp_search_snapshots', function (Blueprint $table) use (
            $hasSingleSnapshotConstraint,
            $hasHistoryIndex,
        ) {
            if ($hasHistoryIndex) {
                $table->dropIndex(['user_id', 'created_at']);
            }

            if (! $hasSingleSnapshotConstraint) {
                $table->unique('user_id');
            }
        });
    }
};

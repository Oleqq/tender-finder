<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('source_feeds')
            ->where('source', 'eis_rss')
            ->update([
                'status' => 'paused',
                'next_poll_at' => null,
                'updated_at' => now(),
            ]);

        $legacyQueryIds = DB::table('source_feed_search_queries')
            ->join('source_feeds', 'source_feeds.id', '=', 'source_feed_search_queries.source_feed_id')
            ->where('source_feeds.source', 'eis_rss')
            ->pluck('source_feed_search_queries.search_query_id');

        foreach ($legacyQueryIds as $queryId) {
            $hasRostenderSource = DB::table('rostender_feed_search_query')
                ->where('search_query_id', $queryId)
                ->exists();

            if (! $hasRostenderSource) {
                DB::table('search_queries')
                    ->where('id', $queryId)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'paused',
                        'paused_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Retiring an external source is intentionally forward-only. Historical
        // tenders and links remain in the database for audit and user history.
    }
};

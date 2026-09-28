<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        match (DB::getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE tenders ALTER COLUMN source DROP DEFAULT'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE tenders ALTER source DROP DEFAULT'),
            default => null,
        };
    }

    public function down(): void
    {
        // Forward-only cleanup: retired source defaults must not be restored.
    }
};

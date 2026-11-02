<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_events', function (Blueprint $table): void {
            $table->string('source', 32)->nullable();
            $table->unsignedBigInteger('telegram_update_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('consent_events', function (Blueprint $table): void {
            $table->dropUnique(['telegram_update_id']);
            $table->dropColumn(['source', 'telegram_update_id']);
        });
    }
};

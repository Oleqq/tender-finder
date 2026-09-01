<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_user_states', function (Blueprint $table) {
            $table->text('note')->nullable()->after('status');
            $table->json('tags')->nullable()->after('note');
            $table->date('next_action_on')->nullable()->after('tags')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tender_user_states', function (Blueprint $table) {
            $table->dropIndex(['next_action_on']);
            $table->dropColumn(['note', 'tags', 'next_action_on']);
        });
    }
};

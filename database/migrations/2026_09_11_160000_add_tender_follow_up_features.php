<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_user_states', function (Blueprint $table): void {
            $table->boolean('deadline_reminders_enabled')->default(false);
            $table->boolean('action_reminder_enabled')->default(false);
            $table->boolean('watch_changes')->default(false);
            $table->timestamp('watch_started_at')->nullable();
        });
        Schema::table('tenders', function (Blueprint $table): void {
            $table->timestamp('watch_checked_at')->nullable()->index();
        });
        Schema::create('tender_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->json('changes');
            $table->timestamps();
        });
        Schema::create('tender_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_query_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 500);
            $table->string('exclusion_kind', 20)->nullable();
            $table->string('exclusion_value', 200)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_feedback');
        Schema::dropIfExists('tender_changes');
        Schema::table('tenders', fn (Blueprint $table) => $table->dropColumn('watch_checked_at'));
        Schema::table('tender_user_states', fn (Blueprint $table) => $table->dropColumn([
            'deadline_reminders_enabled', 'action_reminder_enabled', 'watch_changes', 'watch_started_at',
        ]));
    }
};

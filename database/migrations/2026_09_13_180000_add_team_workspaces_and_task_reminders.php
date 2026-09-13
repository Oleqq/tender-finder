<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $t->string('name', 120);
            $t->timestamps();
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16);
            $t->timestamps();
            $t->unique(['team_id', 'user_id']);
        });
        Schema::create('team_invitations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained()->cascadeOnDelete();
            $t->string('token_hash', 64)->unique();
            $t->string('role', 16);
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::table('tender_participations', function (Blueprint $t) {
            $t->dropUnique(['user_id', 'tender_id']);
            $t->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['team_id', 'tender_id']);
        });
        DB::statement('CREATE UNIQUE INDEX participation_private_unique ON tender_participations (user_id, tender_id) WHERE team_id IS NULL');
        Schema::table('tender_checklist_items', function (Blueprint $t) {
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('reminder_enabled')->default(false);
        });
        Schema::table('tender_participation_events', function (Blueprint $t) {
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('checklist_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->json('items');
            $t->timestamps();
        });
        Schema::create('checklist_template_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $t->foreignId('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $t->unique(['participation_id', 'template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_template_applications');
        Schema::dropIfExists('checklist_templates');
        Schema::table('tender_participation_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('actor_id'));
        Schema::table('tender_checklist_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assignee_id');
            $t->dropColumn('reminder_enabled');
        });
        DB::table('tender_participations')->whereNotNull('team_id')->delete();
        DB::statement('DROP INDEX participation_private_unique');
        Schema::table('tender_participations', function (Blueprint $t) {
            $t->dropUnique(['team_id', 'tender_id']);
            $t->dropConstrainedForeignId('team_id');
            $t->dropConstrainedForeignId('assignee_id');
            $t->unique(['user_id', 'tender_id']);
        });
        Schema::dropIfExists('team_invitations');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('name')->index();
        });

        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('items');
        });

        Schema::create('checklist_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('checklist_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->json('items');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['template_id', 'version']);
        });

        DB::table('checklist_templates')->orderBy('id')->each(function (object $template): void {
            DB::table('checklist_template_versions')->insert([
                'template_id' => $template->id,
                'version' => 1,
                'name' => $template->name,
                'items' => $template->items,
                'actor_id' => $template->user_id,
                'created_at' => $template->created_at ?? now(),
            ]);
        });

        Schema::table('checklist_template_applications', function (Blueprint $table) {
            $table->dropUnique(['participation_id', 'template_id']);
            $table->unsignedInteger('template_version')->default(1)->after('template_id');
            $table->unique(['participation_id', 'template_id', 'template_version'], 'checklist_template_application_version_unique');
        });

        Schema::create('team_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->index();
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_activity_logs');

        Schema::table('checklist_template_applications', function (Blueprint $table) {
            $table->dropUnique('checklist_template_application_version_unique');
            $table->dropColumn('template_version');
            $table->unique(['participation_id', 'template_id']);
        });

        Schema::dropIfExists('checklist_template_versions');
        Schema::table('checklist_templates', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::table('teams', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};

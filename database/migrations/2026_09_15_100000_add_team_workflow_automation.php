<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('review_sla_hours')->default(24);
            $table->string('assignment_mode', 24)->default('manual');
            $table->foreignId('assignment_cursor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('notify_assignments')->default(true);
            $table->boolean('notify_sla')->default(true);
            $table->boolean('digest_enabled')->default(true);
            $table->time('digest_time')->default('09:00');
            $table->boolean('approval_enabled')->default(false);
            $table->decimal('approval_min_revenue', 18, 2)->nullable();
            $table->decimal('approval_max_margin_percent', 7, 2)->nullable();
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('team_tender_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('source', 32)->nullable();
            $table->foreignId('search_query_id')->nullable()->constrained()->nullOnDelete();
            $table->string('region', 160)->nullable();
            $table->decimal('min_budget', 18, 2)->nullable();
            $table->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['team_id', 'enabled', 'priority']);
        });

        Schema::table('team_tender_reviews', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('sla_alerted_at')->nullable();
        });

        Schema::table('tender_feed_views', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('participation_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('required_approvals');
            $table->unsignedInteger('economics_version');
            $table->text('note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['participation_id', 'status']);
        });

        Schema::create('participation_approval_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('participation_approval_requests')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 16);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['approval_request_id', 'approver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_approval_votes');
        Schema::dropIfExists('participation_approval_requests');
        Schema::table('tender_feed_views', fn (Blueprint $table) => $table->dropConstrainedForeignId('team_id'));
        Schema::table('team_tender_reviews', function (Blueprint $table) {
            $table->dropColumn(['due_at', 'assigned_at', 'sla_alerted_at']);
        });
        Schema::dropIfExists('team_tender_routing_rules');
        Schema::dropIfExists('team_workflow_settings');
    }
};

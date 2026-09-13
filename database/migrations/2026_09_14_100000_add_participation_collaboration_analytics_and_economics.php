<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tender_participations', function (Blueprint $table) {
            $table->decimal('planned_revenue', 18, 2)->nullable();
            $table->decimal('planned_cost', 18, 2)->nullable();
            $table->decimal('security_cost', 18, 2)->nullable();
            $table->decimal('commission_cost', 18, 2)->nullable();
            $table->decimal('other_cost', 18, 2)->nullable();
            $table->decimal('actual_revenue', 18, 2)->nullable();
            $table->decimal('actual_cost', 18, 2)->nullable();
            $table->string('participation_decision', 16)->nullable()->index();
            $table->text('decision_note')->nullable();
            $table->unsignedInteger('economics_version')->default(1);
        });

        Schema::create('participation_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['participation_id', 'id']);
        });

        Schema::create('participation_comment_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('participation_comments')->cascadeOnDelete();
            $table->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 16);
            $table->text('body')->nullable();
            $table->timestamp('created_at');
            $table->unique(['comment_id', 'version']);
        });

        Schema::create('participation_comment_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('participation_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['comment_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('participation_comment_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_comment_id')->default(0);
            $table->timestamp('updated_at');
            $table->unique(['participation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_comment_reads');
        Schema::dropIfExists('participation_comment_mentions');
        Schema::dropIfExists('participation_comment_versions');
        Schema::dropIfExists('participation_comments');

        Schema::table('tender_participations', function (Blueprint $table) {
            $table->dropColumn([
                'planned_revenue', 'planned_cost', 'security_cost', 'commission_cost',
                'other_cost', 'actual_revenue', 'actual_cost', 'participation_decision',
                'decision_note', 'economics_version',
            ]);
        });
    }
};

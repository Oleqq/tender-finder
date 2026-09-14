<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_search_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('search_query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'search_query_id']);
        });

        Schema::create('team_tender_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('new')->index();
            $table->text('rejection_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['team_id', 'tender_id']);
            $table->index(['team_id', 'assignee_id', 'status']);
        });

        Schema::create('team_tender_review_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('team_tender_reviews')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['review_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_tender_review_comments');
        Schema::dropIfExists('team_tender_reviews');
        Schema::dropIfExists('team_search_queries');
    }
};

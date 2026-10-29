<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('creation_key');
            $table->string('creation_hash', 64);
            $table->string('title', 160);
            $table->string('topic', 24);
            $table->text('context')->nullable();
            $table->text('body');
            $table->json('source');
            $table->string('tender_hash', 64);
            $table->unsignedInteger('source_revision')->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('marked_sent_at')->nullable();
            $table->foreignId('marked_sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sent_reference', 500)->nullable();
            $table->text('answer')->nullable();
            $table->string('answer_source', 500)->nullable();
            $table->date('answer_received_on')->nullable();
            $table->timestamp('answer_updated_at')->nullable();
            $table->foreignId('answer_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['participation_id', 'creation_key']);
            $table->index(['participation_id', 'id']);
        });
        Schema::create('tender_question_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('tender_questions')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 32);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['question_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_question_events');
        Schema::dropIfExists('tender_questions');
    }
};

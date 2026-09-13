<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 24)->default('studying');
            $table->text('loss_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'tender_id']);
            $table->index(['user_id', 'stage']);
        });
        Schema::create('tender_participation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->string('from_stage', 24)->nullable();
            $table->string('to_stage', 24);
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('tender_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->string('title', 240);
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['due_on', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_checklist_items');
        Schema::dropIfExists('tender_participation_events');
        Schema::dropIfExists('tender_participations');
    }
};

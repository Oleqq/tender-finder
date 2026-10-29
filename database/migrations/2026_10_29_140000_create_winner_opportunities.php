<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winner_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('creation_key');
            $table->string('creation_hash', 64);
            $table->json('evidence');
            $table->text('body')->nullable();
            $table->json('draft_source')->nullable();
            $table->string('outcome', 24)->default('new');
            $table->text('outcome_note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'creation_key']);
            $table->index(['user_id', 'archived_at', 'id']);
        });
        Schema::create('winner_opportunity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('winner_opportunities')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 24);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['opportunity_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winner_opportunity_events');
        Schema::dropIfExists('winner_opportunities');
    }
};

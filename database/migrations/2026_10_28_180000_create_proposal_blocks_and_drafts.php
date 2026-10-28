<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title', 160);
            $table->string('category', 24);
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index('user_id');
        });
        Schema::create('proposal_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('tender_id')->constrained();
            $table->foreignId('team_id')->nullable()->constrained();
            $table->string('scope_key', 40);
            $table->text('body');
            $table->json('snapshot');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'tender_id', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_drafts');
        Schema::dropIfExists('proposal_blocks');
    }
};

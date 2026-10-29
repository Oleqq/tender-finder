<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repeat_tender_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->json('original');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('scan_after');
            $table->timestamp('checked_at')->nullable();
            $table->boolean('scan_limited')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'tender_id']);
        });
        Schema::create('repeat_tender_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_id')->constrained('repeat_tender_watches')->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('possible');
            $table->json('snapshot');
            $table->json('basis');
            $table->json('evidence')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['watch_id', 'tender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repeat_tender_candidates');
        Schema::dropIfExists('repeat_tender_watches');
    }
};

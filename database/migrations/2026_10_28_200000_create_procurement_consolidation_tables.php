<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->string('status', 20)->default('draft');
            $table->json('selection');
            $table->json('offers');
            $table->json('combined_offer');
            $table->json('confirmed_result')->nullable();
            $table->text('conditions_note')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['team_id', 'user_id', 'status']);
        });
        Schema::create('procurement_needs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('confirmed_comparison_id')->nullable()->constrained('procurement_comparisons')->nullOnDelete();
            $table->string('title', 160);
            $table->string('match_key', 200)->index();
            $table->text('specification')->nullable();
            $table->string('unit', 30);
            $table->decimal('quantity', 8, 3);
            $table->date('required_on')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_needs');
        Schema::dropIfExists('procurement_comparisons');
    }
};

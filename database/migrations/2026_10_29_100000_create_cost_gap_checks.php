<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_gap_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->unique()->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('sources');
            $table->json('rows');
            $table->json('decisions')->nullable();
            $table->text('manual_requirements')->nullable();
            $table->string('source_hash', 64);
            $table->string('tender_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('cost_gap_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cost_gap_check_id')->constrained()->cascadeOnDelete();
            $table->string('requirement_key', 80);
            $table->string('category', 40);
            $table->decimal('amount', 18, 2);
            $table->decimal('previous_amount', 18, 2);
            $table->decimal('result_amount', 18, 2);
            $table->unsignedInteger('economics_version');
            $table->json('snapshot');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['cost_gap_check_id', 'requirement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_gap_transfers');
        Schema::dropIfExists('cost_gap_checks');
    }
};

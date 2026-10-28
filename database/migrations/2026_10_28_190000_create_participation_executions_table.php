<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participation_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->unique()->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('baseline');
            $table->json('expenses');
            $table->decimal('received_revenue', 12, 2)->nullable();
            $table->decimal('remaining_revenue', 12, 2)->nullable();
            $table->boolean('costs_confirmed')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participation_executions');
    }
};

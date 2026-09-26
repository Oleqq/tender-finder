<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participation_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participation_id')->constrained('tender_participations')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('tender_checklist_items')->nullOnDelete();
            $table->string('title', 240);
            $table->string('type', 32)->default('other');
            $table->string('status', 24)->default('needed');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['participation_id', 'archived_at']);
        });

        Schema::create('participation_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('participation_documents')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_kind', 16);
            $table->text('source_url')->nullable();
            $table->string('disk', 32)->nullable();
            $table->text('storage_path')->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('created_at');
            $table->index(['document_id', 'id']);
        });

        Schema::create('calendar_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'team_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_subscriptions');
        Schema::dropIfExists('participation_document_versions');
        Schema::dropIfExists('participation_documents');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_mvp_search_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('query', 120);
            $table->string('source', 32);
            $table->json('tender_ids');
            $table->unsignedInteger('items_seen')->default(0);
            $table->unsignedInteger('items_matched')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedSmallInteger('pages_requested')->default(1);
            $table->unsignedSmallInteger('pages_loaded')->default(0);
            $table->boolean('partially_loaded')->default(false);
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_mvp_search_snapshots');
    }
};

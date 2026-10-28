<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->string('filename')->nullable();
            $table->timestamps();
        });
        Schema::create('price_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_catalog_id')->constrained()->cascadeOnDelete();
            $table->string('name', 300);
            $table->text('characteristics')->nullable();
            $table->decimal('price', 15, 2)->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->string('unit', 40)->nullable();
            $table->string('region')->nullable();
            $table->timestamps();
        });
        Schema::create('price_catalog_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->json('requirements')->nullable();
            $table->boolean('complete')->default(false);
            $table->string('filename')->nullable();
            $table->json('reviews')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'tender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_catalog_comparisons');
        Schema::dropIfExists('price_catalog_items');
        Schema::dropIfExists('price_catalogs');
    }
};

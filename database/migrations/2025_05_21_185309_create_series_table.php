<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('new')->default(true);
            $table->unsignedInteger('source_category_id')->nullable();
            $table->string('import_batch_no');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('cover')->nullable();
            $table->longText('plot')->nullable();
            $table->string('genre')->nullable();
            $table->string('release_date')->nullable();
            $table->longText('cast')->nullable();
            $table->string('director')->nullable();
            $table->string('rating')->nullable();
            $table->double('rating_5based')->nullable();
            $table->jsonb('backdrop_path')->nullable();
            $table->string('youtube_trailer')->nullable();
            $table->boolean('enabled')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};

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

        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('new')->default(true);
            $table->unsignedInteger('source_season_id')->nullable();
            $table->string('import_batch_no');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('category_id')->nullable()->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('series_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('season_number')->nullable();
            $table->unsignedInteger('episode_count')->nullable();
            $table->string('cover')->nullable();
            $table->string('cover_big')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};

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

        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('new')->default(true);
            $table->unsignedInteger('source_episode_id')->nullable();
            $table->string('import_batch_no');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('series_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('episode_num')->nullable();
            $table->string('container_extension')->nullable();
            $table->text('url')->nullable();
            $table->string('custom_sid')->nullable();
            $table->string('added')->nullable();
            $table->unsignedInteger('season')->nullable();
            $table->timestamps();
            $table->unique(['source_episode_id', 'playlist_id']);
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episodes');
    }
};

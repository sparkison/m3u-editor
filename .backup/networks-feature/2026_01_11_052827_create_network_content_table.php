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

        Schema::create('network_content', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->morphs('contentable'); // contentable_type, contentable_id (Episode, Channel for VOD)
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('weight')->default(1); // For shuffle mode weighting
            $table->timestamps();

            $table->unique(['network_id', 'contentable_type', 'contentable_id'], 'network_content_unique');
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_content');
    }
};

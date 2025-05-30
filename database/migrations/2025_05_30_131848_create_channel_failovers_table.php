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

        Schema::create('channel_failovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('channel_failover_id')->constrained('channels')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('sort')->nullable()->default(0);
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
        Schema::dropIfExists('channel_failovers');
    }
};

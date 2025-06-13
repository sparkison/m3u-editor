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
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('custom_playlist_id')
                ->nullable()
                ->after('playlist_id')
                ->constrained()
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->boolean('is_custom')
                ->default(false)
                ->after('custom_playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['custom_playlist_id']);

            // Then drop the column
            $table->dropColumn(['custom_playlist_id', 'is_custom']);
        });
    }
};

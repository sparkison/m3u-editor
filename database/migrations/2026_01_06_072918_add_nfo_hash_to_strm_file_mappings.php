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
        Schema::table('strm_file_mappings', function (Blueprint $table) {
            // Add hash column to track NFO file content hash
            // This allows us to skip file reads when checking if NFO needs updating
            $table->string('nfo_hash', 64)->nullable()->after('path_options');
            $table->index('nfo_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strm_file_mappings', function (Blueprint $table) {
            $table->dropIndex(['nfo_hash']);
            $table->dropColumn('nfo_hash');
        });
    }
};

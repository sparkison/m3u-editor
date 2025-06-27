<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, remove duplicate records - keep only the most recent one for each playlist_auth_id
        DB::statement("
            DELETE a1 FROM authenticatables a1
            INNER JOIN authenticatables a2 
            WHERE a1.playlist_auth_id = a2.playlist_auth_id 
            AND a1.id < a2.id
        ");

        // Now add the unique constraint
        Schema::table('authenticatables', function (Blueprint $table) {
            $table->unique('playlist_auth_id', 'unique_playlist_auth_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('authenticatables', function (Blueprint $table) {
            $table->dropUnique('unique_playlist_auth_id');
        });
    }
};

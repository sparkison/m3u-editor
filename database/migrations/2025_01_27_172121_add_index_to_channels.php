<?php

use App\Models\Channel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $uniqueColumns = ['name', 'group', 'playlist_id', 'user_id'];
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove duplicate rows
        DB::statement('
            DELETE FROM channels
                WHERE id NOT IN (
                SELECT id FROM (
                    SELECT MIN(id) AS id
                    FROM channels
                    GROUP BY name, "group", playlist_id, user_id
                ) AS temp_ids
            );
        ');

        // Add unique index
        Schema::table('channels', function (Blueprint $table) {
            $table->unique($this->uniqueColumns);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique($this->uniqueColumns);
        });
    }
};

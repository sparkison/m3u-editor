<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $uniqueColumns = ['name', 'channel_id', 'epg_id', 'user_id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->unique($this->uniqueColumns);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->dropUnique($this->uniqueColumns);
        });
    }
};

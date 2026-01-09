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
        Schema::table('epgs', function (Blueprint $table) {
            $table->unsignedInteger('channel_count')
                ->default(0)
                ->after('progress');
            $table->unsignedInteger('programme_count')
                ->default(0)
                ->after('channel_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn('channel_count');
            $table->dropColumn('programme_count');
        });
    }
};

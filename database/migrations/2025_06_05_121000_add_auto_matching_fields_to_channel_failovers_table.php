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
        Schema::table('channel_failovers', function (Blueprint $table) {
            $table->boolean('auto_matched')->default(false)->after('sort');
            $table->decimal('match_quality', 5, 4)->nullable()->after('auto_matched');
            $table->string('match_type')->nullable()->after('match_quality');
            
            $table->index('auto_matched');
            $table->index('match_quality');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_failovers', function (Blueprint $table) {
            $table->dropIndex(['auto_matched']);
            $table->dropIndex(['match_quality']);
            $table->dropColumn(['auto_matched', 'match_quality', 'match_type']);
        });
    }
};

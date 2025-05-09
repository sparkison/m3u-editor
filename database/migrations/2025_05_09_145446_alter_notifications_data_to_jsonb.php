<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(
            <<<SQL
ALTER TABLE notifications
  ALTER COLUMN data
    TYPE jsonb
    USING data::jsonb;
SQL
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(
            <<<SQL
ALTER TABLE notifications
  ALTER COLUMN data
    TYPE text
    USING data::text;
SQL
        );
    }
};

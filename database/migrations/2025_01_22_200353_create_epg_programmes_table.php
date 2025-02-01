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
        // Schema::disableForeignKeyConstraints();

        // Schema::create('epg_programmes', function (Blueprint $table) {
        //     $table->id();
        //     $table->string('name')->nullable();
        //     $table->string('channel_id');
        //     $table->string('import_batch_no')->nullable();
        //     $table->longText('data');
        //     $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
        //     $table->foreignId('epg_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
        //     $table->timestamps();
        // });

        // Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('epg_programmes');
    }
};

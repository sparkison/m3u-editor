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
        Schema::create('channels', function (Blueprint $table) {
            $table->string('name');
            $table->boolean('enabled');
            $table->unsignedInteger('channel')->nullable();
            $table->string('url');
            $table->string('logo');
            $table->string('group');
            $table->string('id');
            $table->string('lang');
            $table->string('country');
            $table->foreignId('playlist_id');
            $table->foreignId('group_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};

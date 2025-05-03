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
        Schema::connection('jobs')->dropIfExists('jobs');
        Schema::connection('jobs')->create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('batch_no');
            $table->longText('payload');
            $table->json('variables')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('jobs')->dropIfExists('jobs');
    }
};

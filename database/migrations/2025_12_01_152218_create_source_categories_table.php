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
        Schema::disableForeignKeyConstraints();

        Schema::create('source_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('source_category_id');
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['source_category_id', 'playlist_id']);
        });

        Schema::enableForeignKeyConstraints();

        // Migrate existing categories to source_categories table
        $categories = DB::table('categories')->get();
        foreach ($categories as $category) {
            DB::table('source_categories')->insert([
                'name' => $category->name,
                'source_category_id' => $category->source_category_id,
                'playlist_id' => $category->playlist_id,
                'created_at' => $category->created_at,
                'updated_at' => $category->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_categories');
    }
};

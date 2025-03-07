<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SimilaritySearchService
{
    /**
     * Search for similar records in the database using trigram similarity or Levenshtein distance.
     *
     * @param string $table - The table to search in
     * @param string $column - The column to compare
     * @param string $searchTerm - The search query
     * @param int $limit - Maximum results to return
     * @return \Illuminate\Support\Collection
     */
    public function searchSimilar($table, $column, $searchTerm, $limit = 10)
    {
        $connection = config('database.default');

        if ($connection === 'mysql' || $connection === 'sqlite') {
            return DB::table($table)
                ->select($column, DB::raw("trigram_similarity($column, ?) as similarity"))
                ->setBindings([$searchTerm]) // Explicitly set the binding
                ->orderByDesc('similarity')
                ->limit($limit)
                ->get();
        }

        // Fallback to Levenshtein if trigram similarity is unavailable
        return DB::table($table)
            ->select($column, DB::raw("levenshtein($column, ?) as distance"))
            ->setBindings([$searchTerm]) // Explicitly set the binding
            ->orderBy('distance')
            ->limit($limit)
            ->get();
    }
}

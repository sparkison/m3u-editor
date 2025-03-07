<?php

namespace App\Providers;

use Illuminate\Database\QueryException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class SimilarityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Ensure similarity functions are available on boot
        // $this->registerSimilarityFunctions();
    }

    private function registerSimilarityFunctions(): void
    {
        try {
            $connection = config('database.default');
            if ($connection === 'sqlite') {
                $pdo = DB::connection()->getPdo();

                // Define the similarity functions as closures
                $trigramSimilarity = static function ($s1, $s2) {
                    similar_text($s1, $s2, $percent);
                    return $percent / 100; // Normalize similarity score between 0 and 1
                };
                $levenshteinDistance = static function ($s1, $s2) {
                    return levenshtein($s1, $s2);
                };

                // Register the functions using Closure::fromCallable()
                $pdo->sqliteCreateFunction('trigram_similarity', \Closure::fromCallable($trigramSimilarity), 2);
                $pdo->sqliteCreateFunction('levenshtein', \Closure::fromCallable($levenshteinDistance), 2);
            } elseif ($connection === 'mysql') {
                // Register the MySQL similarity functions (requires custom SQL functions)
                DB::statement("DROP FUNCTION IF EXISTS trigram_similarity");
                DB::statement("
                CREATE FUNCTION trigram_similarity(s1 TEXT, s2 TEXT)
                RETURNS FLOAT DETERMINISTIC
                BEGIN
                    DECLARE l1 INT;
                    DECLARE l2 INT;
                    DECLARE common INT;

                    SET l1 = CHAR_LENGTH(s1);
                    SET l2 = CHAR_LENGTH(s2);
                    SET common = (CHAR_LENGTH(s1) - CHAR_LENGTH(REPLACE(s1, s2, ''))) / CHAR_LENGTH(s2);

                    RETURN (2.0 * common) / (l1 + l2);
                END;
            ");
                DB::statement("DROP FUNCTION IF EXISTS levenshtein");
                DB::statement("
                CREATE FUNCTION levenshtein(s1 TEXT, s2 TEXT)
                RETURNS INT DETERMINISTIC
                BEGIN
                    DECLARE s1_len INT;
                    DECLARE s2_len INT;

                    SET s1_len = CHAR_LENGTH(s1);
                    SET s2_len = CHAR_LENGTH(s2);

                    RETURN (s1_len + s2_len - 2 * CHAR_LENGTH(REGEXP_REPLACE(s1, s2, '')));
                END;
            ");
            }
        } catch (QueryException $e) {
        }
    }
}

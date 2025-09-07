<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('playlists')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('shared_streams', function (Blueprint $table) {
            $table->string('format')->default('ts')->change();
        });

        if (! Schema::hasIndex('categories', 'categories_playlist_id_source_category_id_unique')) {
            $duplicateIds = DB::table('categories')
                ->select('id')
                ->whereNotNull('playlist_id')
                ->whereNotNull('source_category_id')
                ->whereNotIn('id', function ($query) {
                    $query->select(DB::raw('MIN(id)'))
                        ->from('categories')
                        ->whereNotNull('playlist_id')
                        ->whereNotNull('source_category_id')
                        ->groupBy('playlist_id', 'source_category_id');
                })
                ->pluck('id');

            if ($duplicateIds->isNotEmpty()) {
                DB::table('categories')->whereIn('id', $duplicateIds)->delete();
            }

            Schema::table('categories', function (Blueprint $table) {
                $table->unique(
                    ['playlist_id', 'source_category_id'],
                    'categories_playlist_id_source_category_id_unique'
                );
            });
        }

        if (! Schema::hasIndex('groups', 'groups_playlist_id_name_internal_unique')) {
            $duplicateIds = DB::table('groups')
                ->select('id')
                ->whereNotNull('playlist_id')
                ->whereNotNull('name_internal')
                ->whereNotIn('id', function ($query) {
                    $query->select(DB::raw('MIN(id)'))
                        ->from('groups')
                        ->whereNotNull('playlist_id')
                        ->whereNotNull('name_internal')
                        ->groupBy('playlist_id', 'name_internal');
                })
                ->pluck('id');

            if ($duplicateIds->isNotEmpty()) {
                DB::table('groups')->whereIn('id', $duplicateIds)->delete();
            }

            Schema::table('groups', function (Blueprint $table) {
                $table->unique(['playlist_id', 'name_internal'], 'groups_playlist_id_name_internal_unique');
            });
        }

        if (! Schema::hasIndex('series', 'series_playlist_id_source_series_id_unique')) {
            $duplicateIds = DB::table('series')
                ->select('id')
                ->whereNotNull('playlist_id')
                ->whereNotNull('source_series_id')
                ->whereNotIn('id', function ($query) {
                    $query->select(DB::raw('MIN(id)'))
                        ->from('series')
                        ->whereNotNull('playlist_id')
                        ->whereNotNull('source_series_id')
                        ->groupBy('playlist_id', 'source_series_id');
                })
                ->pluck('id');

            if ($duplicateIds->isNotEmpty()) {
                DB::table('series')->whereIn('id', $duplicateIds)->delete();
            }

            Schema::table('series', function (Blueprint $table) {
                $table->unique(
                    ['playlist_id', 'source_series_id'],
                    'series_playlist_id_source_series_id_unique'
                );
            });
        }

        if (! Schema::hasIndex('seasons', 'seasons_playlist_id_source_season_id_unique')) {
            $duplicateIds = DB::table('seasons')
                ->select('id')
                ->whereNotNull('playlist_id')
                ->whereNotNull('source_season_id')
                ->whereNotIn('id', function ($query) {
                    $query->select(DB::raw('MIN(id)'))
                        ->from('seasons')
                        ->whereNotNull('playlist_id')
                        ->whereNotNull('source_season_id')
                        ->groupBy('playlist_id', 'source_season_id');
                })
                ->pluck('id');

            if ($duplicateIds->isNotEmpty()) {
                DB::table('seasons')->whereIn('id', $duplicateIds)->delete();
            }

            Schema::table('seasons', function (Blueprint $table) {
                $table->unique(
                    ['playlist_id', 'source_season_id'],
                    'seasons_playlist_id_source_season_id_unique'
                );
            });
        }

        if (! Schema::hasIndex('channel_failovers', 'channel_failovers_channel_id_channel_failover_id_unique')) {
            $duplicateIds = DB::table('channel_failovers')
                ->select('id')
                ->whereNotIn('id', function ($query) {
                    $query->select(DB::raw('MIN(id)'))
                        ->from('channel_failovers')
                        ->groupBy('channel_id', 'channel_failover_id');
                })
                ->pluck('id');

            if ($duplicateIds->isNotEmpty()) {
                DB::table('channel_failovers')->whereIn('id', $duplicateIds)->delete();
            }

            Schema::table('channel_failovers', function (Blueprint $table) {
                $table->unique(
                    ['channel_id', 'channel_failover_id'],
                    'channel_failovers_channel_id_channel_failover_id_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('seasons', 'seasons_playlist_id_source_season_id_unique')) {
            Schema::table('seasons', function (Blueprint $table) {
                $table->dropUnique('seasons_playlist_id_source_season_id_unique');
            });
        }

        if (Schema::hasIndex('series', 'series_playlist_id_source_series_id_unique')) {
            Schema::table('series', function (Blueprint $table) {
                $table->dropUnique('series_playlist_id_source_series_id_unique');
            });
        }

        if (Schema::hasIndex('groups', 'groups_playlist_id_name_internal_unique')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropUnique('groups_playlist_id_name_internal_unique');
            });
        }

        if (Schema::hasIndex('categories', 'categories_playlist_id_source_category_id_unique')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropUnique('categories_playlist_id_source_category_id_unique');
            });
        }

        if (Schema::hasIndex('channel_failovers', 'channel_failovers_channel_id_channel_failover_id_unique')) {
            Schema::table('channel_failovers', function (Blueprint $table) {
                $table->dropUnique('channel_failovers_channel_id_channel_failover_id_unique');
            });
        }

        Schema::table('shared_streams', function (Blueprint $table) {
            $table->string('format')->nullable()->default(null)->change();
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};

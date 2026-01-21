<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Support\Facades\DB;

class SortService
{
    /**
     * Bulk-update channels' sort order using DB window functions when available,
     * falling back to a single CASE-based UPDATE to avoid N queries.
     */
    public function bulkSortGroupChannels(Group $record, string $order = 'ASC'): void
    {
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // MySQL (8+)
        if ($driver === 'mysql') {
            DB::statement("UPDATE channels c JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY COALESCE(title_custom, title) {$direction}) AS rn FROM channels WHERE group_id = ?) t ON c.id = t.id SET c.sort = t.rn", [$record->id]);

            return;
        }

        // Postgres
        if (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement("UPDATE channels SET sort = t.rn FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY COALESCE(title_custom, title) {$direction}) AS rn FROM channels WHERE group_id = ?) t WHERE channels.id = t.id", [$record->id]);

            return;
        }

        // SQLite
        if ($driver === 'sqlite') {
            DB::statement("WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY COALESCE(title_custom, title) {$direction}) AS rn FROM channels WHERE group_id = ?) UPDATE channels SET sort = (SELECT rn FROM ranked WHERE ranked.id = channels.id) WHERE group_id = ?", [$record->id, $record->id]);

            return;
        }

        // Fallback: single CASE update
        $ids = $record->channels()->orderByRaw("COALESCE(title_custom, title) {$order}")->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        $cases = [];
        $i = 1;
        foreach ($ids as $id) {
            $cases[] = "WHEN {$id} THEN {$i}";
            $i++;
        }

        $casesSql = implode(' ', $cases);
        $idsSql = implode(',', $ids);

        DB::statement("UPDATE channels SET sort = CASE id {$casesSql} END WHERE id IN ({$idsSql})");
    }

    /**
     * Bulk recount channel numbers.
     */
    public function bulkRecountGroupChannels(Group $record, int $start = 1): void
    {
        $offset = max(0, $start - 1);
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            DB::statement('UPDATE channels c JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) t ON c.id = t.id SET c.channel = t.rn + ?', [$record->id, $offset]);

            return;
        }

        if (str_starts_with($driver, 'pgsql') || $driver === 'postgresql' || $driver === 'postgres') {
            DB::statement('UPDATE channels SET channel = t.rn + ? FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) t WHERE channels.id = t.id', [$offset, $record->id]);

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('WITH ranked AS (SELECT id, ROW_NUMBER() OVER (ORDER BY sort) AS rn FROM channels WHERE group_id = ?) UPDATE channels SET channel = (SELECT rn FROM ranked WHERE ranked.id = channels.id) + ? WHERE group_id = ?', [$record->id, $offset, $record->id]);

            return;
        }

        // Fallback: CASE update
        $ids = $record->channels()->orderBy('sort')->pluck('id')->all();
        if (empty($ids)) {
            return;
        }

        $cases = [];
        $i = $start;
        foreach ($ids as $id) {
            $cases[] = "WHEN {$id} THEN {$i}";
            $i++;
        }

        $casesSql = implode(' ', $cases);
        $idsSql = implode(',', $ids);

        DB::statement("UPDATE channels SET channel = CASE id {$casesSql} END WHERE id IN ({$idsSql})");
    }
}

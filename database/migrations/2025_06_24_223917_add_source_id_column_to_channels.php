<?php

use App\Models\Channel;
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
        Schema::table('channels', function (Blueprint $table) {
            $table->string('source_id')->nullable()->after('stream_id');
        });

        // Update existing channels to set source_id to their stream_id
        // The `url` variable will contain the stream ID in the last path, minus the extension
        // E.g., "https://example.com/stream/12345.m3u8" will set source_id to "12345"
        // This assumes that the URL is well-formed and contains a stream ID at the end
        $channels = Channel::query()
            ->whereNotNull('url')
            ->cursor();

        foreach ($channels->chunk(500) as $chunk) {
            $bulk = [];
            foreach ($chunk as $channel) {
                $urlParts = explode('/', $channel->url);
                $streamIdWithExtension = end($urlParts);
                $streamId = pathinfo($streamIdWithExtension, PATHINFO_FILENAME); // Get
                $bulk[] = [
                    'id' => $channel->id,
                    'source_id' => $streamId,
                ];
            }
            Channel::upsert($bulk, ['id'], ['source_id']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('source_id');
        });
    }
};

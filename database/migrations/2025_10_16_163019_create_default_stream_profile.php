<?php

use App\Models\StreamProfile;
use App\Models\User;
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
        // Create default profile
        if (StreamProfile::query()->count() === 0) {
            // Get the first user in the db
            $user = User::query()->first();
            $defaultProfile = StreamProfile::create([
                'user_id' => $user->id,
                'name' => 'Default Profile',
                'description' => 'Default transcoding profile',
                'args' => '-i {input_url} -c:v libx264 -preset faster -crf {crf|23} -maxrate {maxrate|2500k} -bufsize {bufsize|5000k} -c:a aac -b:a {audio_bitrate|192k} -f mpegts {output_args|pipe:1}',
            ]);

            // Update the settings to assign the default profile to the `default_stream_profile_id` setting
            app(\App\Settings\GeneralSettings::class)->default_stream_profile_id = $defaultProfile->id;
            app(\App\Settings\GeneralSettings::class)->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};

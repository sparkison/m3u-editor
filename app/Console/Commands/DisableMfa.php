<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DisableMfa extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:disable-mfa {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable multi-factor authentication for a user.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $this->info("🔓 Disabling multi-factor authentication for \"$email\" ...");
        $user = User::where('email', $this->argument('email'))->first();
        if ($user) {
            DB::table('breezy_sessions')->where([
                ['authenticatable_id', $user->id],
                ['authenticatable_type', User::class],
            ])->delete();
            $this->info("✅ Multi-factor authentication has been disabled for \"$user->email\"");
        } else {
            $this->error('No user found with that email.');
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

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
            $user->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
            $this->info("✅ Multi-factor authentication has been disabled for \"$user->email\"");
        } else {
            $this->error('No user found with that email.');
        }
    }
}

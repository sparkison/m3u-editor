<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ResetPassword extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-password';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the password for a user';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $this->info('No users found.');
            return;
        } else if ($users->count() === 1) {
            $user = $users->first();
        } else {
            $user = $this->choice('Select a user to reset the password for:', $users->pluck('email')->toArray());
        }

        $password = $this->ask('🔒 Enter the new password');
        if (empty($password)) {
            $this->error('Password cannot be empty.');
            return;
        }
        $user->password = bcrypt($password);
        $user->save();
        $this->info('✅ Password reset successfully!');
        $this->info('🔑 New password: ' . $password);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class TestBroadcasting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-broadcasting';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test broadcasting';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $users = User::get(['id', 'email']);
        if ($users->isEmpty()) {
            $this->info('No users found.');
            return;
        } else if ($users->count() === 1) {
            $user = $users->first();
        } else {
            $user = $this->choice('Select a user to send the broadcast to:', $users->pluck('email')->toArray());
            $user = $users->where('email', $user)->firstOrFail();
        }

        $this->info('Testing broadcasting...');
        Notification::make()
            ->danger()
            ->title("Boradcast testing")
            ->body('Testing system broadcasting')
            ->broadcast($user);

        $this->info('Broadcast sent to: ' . $user->email);
        $this->info('Done.');
        return 0;
    }
}

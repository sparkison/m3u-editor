<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GitInfo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:git-info';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display git information about the current build';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Git Information:');
        $this->line('');

        // Try to read from .git-info file first (for Docker containers)
        $gitInfoFile = base_path('.git-info');
        if (file_exists($gitInfoFile)) {
            $this->line('Source: Docker build arguments');
            $this->line('');

            $gitInfo = file_get_contents($gitInfoFile);
            $lines = explode("\n", mb_trim($gitInfo));

            foreach ($lines as $line) {
                if (mb_strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $this->line(sprintf('  <info>%s:</info> %s', str_replace('_', ' ', ucwords(mb_strtolower($key), '_')), $value ?: 'N/A'));
                }
            }
        }
        // Fallback to environment variables
        elseif (env('GIT_BRANCH') || env('GIT_COMMIT') || env('GIT_TAG')) {
            $this->line('Source: Environment variables');
            $this->line('');

            $this->line(sprintf('  <info>Git Branch:</info> %s', env('GIT_BRANCH', 'N/A')));
            $this->line(sprintf('  <info>Git Commit:</info> %s', env('GIT_COMMIT', 'N/A')));
            $this->line(sprintf('  <info>Git Tag:</info> %s', env('GIT_TAG', 'N/A')));
        }
        // Try to get from git commands (if .git directory exists)
        elseif (is_dir(base_path('.git'))) {
            $this->line('Source: Local git repository');
            $this->line('');

            $branch = mb_trim(shell_exec('cd '.base_path().' && git rev-parse --abbrev-ref HEAD 2>/dev/null') ?: 'N/A');
            $commit = mb_trim(shell_exec('cd '.base_path().' && git rev-parse HEAD 2>/dev/null') ?: 'N/A');
            $tag = mb_trim(shell_exec('cd '.base_path().' && git describe --tags --exact-match 2>/dev/null') ?: 'N/A');

            $this->line(sprintf('  <info>Git Branch:</info> %s', $branch));
            $this->line(sprintf('  <info>Git Commit:</info> %s', $commit));
            $this->line(sprintf('  <info>Git Tag:</info> %s', $tag));
        } else {
            $this->error('No git information available');

            return 1;
        }

        return 0;
    }
}

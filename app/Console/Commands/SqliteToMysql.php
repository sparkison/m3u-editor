<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class SqliteToMysql extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:sqlite-to-mysql
                            {--sqlite= : Path to the SQLite file (relative to base_path())}
                            {--dump= : Path for temporary SQL dump file}
                            {--connection=mysql : Name of the MySQL connection to import into}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export a SQLite DB to SQL and import it into MySQL';

    public function handle()
    {
        // 1. Determine paths and connections
        $sqlitePath = $this->option('sqlite')
            ? base_path($this->option('sqlite'))
            : database_path('database.sqlite');

        if (!File::exists($sqlitePath)) {
            $this->warn("SQLite file not found at {$sqlitePath}, nothing to import.");
            return 0;
        }

        $dumpPath = $this->option('dump')
            ? base_path($this->option('dump'))
            : base_path('sqlite_dump.sql');

        $mysqlConnection = $this->option('connection');

        // 2. Dump SQLite to SQL
        $this->info("Dumping SQLite database from {$sqlitePath} to {$dumpPath}…");
        $dumpCmd = ["sqlite3", $sqlitePath, ".dump"];
        $process = new Process($dumpCmd);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error("SQLite dump failed: " . $process->getErrorOutput());
            return 1;
        }

        File::put($dumpPath, $process->getOutput());
        $this->info("SQLite dump complete.");

        // 3. Import into MySQL
        $this->info("Importing dump into MySQL connection '{$mysqlConnection}'…");

        // Build mysql CLI command
        $dbConfig = config("database.connections.{$mysqlConnection}");
        if (! $dbConfig) {
            $this->error("MySQL connection '{$mysqlConnection}' not found in config/database.php");
            return 1;
        }

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 3306;
        $database = $dbConfig['database'];
        $username = $dbConfig['username'];
        $password = $dbConfig['password'];

        $mysqlCmd = array_filter([
            'mysql',
            "-h{$host}",
            "-P{$port}",
            "-u{$username}",
            $password !== null ? "-p{$password}" : null,
            $database,
        ]);

        $process = new Process($mysqlCmd);
        $process->setInput(File::get($dumpPath));
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error("MySQL import failed: " . $process->getErrorOutput());
            return 1;
        }

        $this->info("MySQL import complete.");

        // 4. Clean up
        File::delete($dumpPath);
        $this->info("Temporary dump file deleted.");

        return 0;
    }
}

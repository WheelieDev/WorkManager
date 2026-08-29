<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class UseDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:use {target? : main or sandbox} {--fresh : rebuild the sandbox and seed it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Switch between the real database and a throwaway sandbox';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $target = $this->argument('target');

        if ($target === null) {
            $this->report();

            return self::SUCCESS;
        }

        if (! in_array($target, ['main', 'sandbox'], strict: true)) {
            $this->components->error("Unknown database [{$target}]. Use main or sandbox.");

            return self::FAILURE;
        }

        if ($this->option('fresh') && $target !== 'sandbox') {
            $this->components->error('Only the sandbox may be rebuilt with --fresh.');

            return self::FAILURE;
        }

        $target === 'sandbox' ? $this->useSandbox() : $this->useMain();

        $this->call('config:clear');

        if ($this->option('fresh') && ! $this->rebuildSandbox()) {
            return self::FAILURE;
        }

        $this->report();

        return self::SUCCESS;
    }

    /**
     * Point the default connection at the sandbox.
     */
    protected function useSandbox(): void
    {
        File::put($this->marker(), "Switch back with: php artisan db:use main\n");

        $this->pointAt(sandbox: true);
    }

    /**
     * Point the default connection back at the real database.
     */
    protected function useMain(): void
    {
        File::delete($this->marker());

        $this->pointAt(sandbox: false);
    }

    /**
     * Repoint the live connection, so anything this command runs next lands
     * in the database that was just chosen rather than the previous one.
     */
    protected function pointAt(bool $sandbox): void
    {
        match ($this->driver()) {
            'sqlite' => $this->pointSqliteAt($sandbox),
            'mysql', 'mariadb' => $this->pointMysqlAt($sandbox),
            default => null,
        };
    }

    /**
     * Repoint sqlite at the file backing the chosen database.
     *
     * Skipped when something else is already in charge of the connection,
     * such as the in-memory database the test suite runs against, which
     * purging would destroy.
     */
    protected function pointSqliteAt(bool $sandbox): void
    {
        $managed = [database_path('database.sqlite'), database_path('sandbox.sqlite')];

        if (! in_array(config('database.connections.sqlite.database'), $managed, true)) {
            return;
        }

        $path = $this->sqlitePath($sandbox);

        if (! File::exists($path)) {
            File::put($path, '');
        }

        config(['database.connections.sqlite.database' => $path]);

        DB::purge('sqlite');
    }

    /**
     * Repoint mysql at the chosen database, creating it when it is new.
     *
     * The sandbox is a whole separate database rather than a separate file,
     * so the same marker keeps working once a project moves off sqlite.
     */
    protected function pointMysqlAt(bool $sandbox): void
    {
        $connection = $this->connection();
        $database = $this->mysqlDatabase($sandbox);

        $this->createMysqlDatabase($database);

        config(["database.connections.{$connection}.database" => $database]);

        DB::purge($connection);
    }

    /**
     * Create the given mysql database if it does not exist yet.
     */
    protected function createMysqlDatabase(string $database): void
    {
        $connection = $this->connection();

        /** @var array<string, mixed> $config */
        $config = config("database.connections.{$connection}");

        config(['database.connections.db_use_server' => ['database' => null] + $config]);

        DB::connection('db_use_server')->statement(sprintf(
            'create database if not exists `%s` character set %s collate %s',
            str_replace('`', '', $database),
            $config['charset'] ?? 'utf8mb4',
            $config['collation'] ?? 'utf8mb4_unicode_ci',
        ));

        DB::purge('db_use_server');
    }

    /**
     * Rebuild the sandbox from scratch and fill it with something to look at.
     *
     * Refuses to run unless the connection really is pointed at the sandbox,
     * because --fresh drops every table it can reach.
     */
    protected function rebuildSandbox(): bool
    {
        if (! $this->isPointedAtSandbox()) {
            $this->components->error(sprintf(
                'Refusing to rebuild: the %s connection is pointed at [%s], not the sandbox.',
                $this->connection(),
                $this->currentDatabase(),
            ));

            return false;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => 'SandboxSeeder', '--force' => true]);
        $this->call('bank-holidays:sync');

        return true;
    }

    /**
     * Determine whether the live connection is really on the sandbox.
     */
    protected function isPointedAtSandbox(): bool
    {
        return match ($this->driver()) {
            'sqlite' => $this->currentDatabase() === $this->sqlitePath(sandbox: true),
            'mysql', 'mariadb' => $this->currentDatabase() === $this->mysqlDatabase(sandbox: true),
            default => false,
        };
    }

    /**
     * Say which database is in use, and where it lives.
     */
    protected function report(): void
    {
        $isSandbox = File::exists($this->marker());

        $this->components->info(sprintf(
            'Using the %s database (%s).',
            $isSandbox ? 'SANDBOX' : 'main',
            str_replace(base_path().'/', '', $this->describe($isSandbox)),
        ));

        if ($isSandbox) {
            $this->components->warn('Sandbox data is disposable. Run "php artisan db:use main" to go back.');
        }
    }

    /**
     * Describe where the chosen database lives, for the driver in use.
     */
    protected function describe(bool $sandbox): string
    {
        return match ($this->driver()) {
            'sqlite' => $this->sqlitePath($sandbox),
            'mysql', 'mariadb' => $this->mysqlDatabase($sandbox),
            default => (string) $this->currentDatabase(),
        };
    }

    /**
     * Get the sqlite file backing the chosen database.
     */
    protected function sqlitePath(bool $sandbox): string
    {
        return database_path($sandbox ? 'sandbox.sqlite' : 'database.sqlite');
    }

    /**
     * Get the mysql database name for the chosen database.
     */
    protected function mysqlDatabase(bool $sandbox): string
    {
        return (string) config($sandbox ? 'database.sandbox_database' : 'database.main_database');
    }

    /**
     * Get the database the live connection is currently opening.
     */
    protected function currentDatabase(): ?string
    {
        return config("database.connections.{$this->connection()}.database");
    }

    /**
     * Get the name of the default connection.
     */
    protected function connection(): string
    {
        return (string) config('database.default');
    }

    /**
     * Get the driver behind the default connection.
     */
    protected function driver(): ?string
    {
        return config("database.connections.{$this->connection()}.driver");
    }

    /**
     * Get the path to the marker file that selects the sandbox.
     */
    protected function marker(): string
    {
        return config('database.sandbox_marker');
    }
}

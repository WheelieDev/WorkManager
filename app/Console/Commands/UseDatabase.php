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

        if ($this->option('fresh')) {
            $this->rebuildSandbox();
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

        $this->pointAt(database_path('sandbox.sqlite'));
    }

    /**
     * Point the default connection back at the real database.
     */
    protected function useMain(): void
    {
        File::delete($this->marker());

        $this->pointAt(database_path('database.sqlite'));
    }

    /**
     * Repoint the live connection, so anything this command runs next lands
     * in the database that was just chosen rather than the previous one.
     *
     * Skipped when something else is already in charge of the connection,
     * such as the in-memory database the test suite runs against, which
     * purging would destroy.
     */
    protected function pointAt(string $path): void
    {
        $managed = [database_path('database.sqlite'), database_path('sandbox.sqlite')];

        if (! in_array(config('database.connections.sqlite.database'), $managed, true)) {
            return;
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }

        config(['database.connections.sqlite.database' => $path]);

        DB::purge('sqlite');
    }

    /**
     * Rebuild the sandbox from scratch and fill it with something to look at.
     */
    protected function rebuildSandbox(): void
    {
        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => 'SandboxSeeder', '--force' => true]);
        $this->call('bank-holidays:sync');
    }

    /**
     * Say which database is in use, and where it lives.
     */
    protected function report(): void
    {
        $isSandbox = File::exists($this->marker());
        $path = database_path($isSandbox ? 'sandbox.sqlite' : 'database.sqlite');

        $this->components->info(sprintf(
            'Using the %s database (%s).',
            $isSandbox ? 'SANDBOX' : 'main',
            str_replace(base_path().'/', '', $path),
        ));

        if ($isSandbox) {
            $this->components->warn('Sandbox data is disposable. Run "php artisan db:use main" to go back.');
        }
    }

    /**
     * Get the path to the marker file that selects the sandbox.
     */
    protected function marker(): string
    {
        return config('database.sandbox_marker');
    }
}

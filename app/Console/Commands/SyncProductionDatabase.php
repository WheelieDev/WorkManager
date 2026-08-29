<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class SyncProductionDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:sync {--force : Take a fresh dump even when a recent one is on disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replace the local database with a copy of production';

    /**
     * How old a dump on disk may be before a fresh one is taken.
     */
    protected int $dumpLifetimeHours = 12;

    /**
     * How many dumps to leave on disk once a new one arrives.
     */
    protected int $dumpsToKeep = 5;

    /**
     * The dump this run is importing.
     */
    protected string $dumpFile;

    /**
     * Where the production database lives.
     *
     * @var array{host: string, port: string, database: string, username: string, password: ?string, query: array<string, string>}
     */
    protected array $production;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->isSafeToRun()) {
            return self::FAILURE;
        }

        $production = $this->productionConnection();

        if ($production === null) {
            return self::FAILURE;
        }

        $this->production = $production;

        if (! $this->takeDump()) {
            return self::FAILURE;
        }

        Schema::dropAllTables();
        $this->components->task('Dropping local tables');

        if (! $this->importDump()) {
            return self::FAILURE;
        }

        $this->call('migrate', ['--force' => true]);

        $this->components->info(sprintf(
            'The local database (%s) is now a copy of production.',
            $this->localConnection()['database'],
        ));

        return self::SUCCESS;
    }

    /**
     * Check the things that must hold before anything is dropped.
     *
     * Every guard here protects a database that is not meant to be replaced:
     * production itself, the sandbox, or anything this command cannot import
     * a MySQL dump into.
     */
    protected function isSafeToRun(): bool
    {
        if (! app()->isLocal()) {
            $this->components->error('This command only runs in a local environment.');

            return false;
        }

        if (File::exists(config('database.sandbox_marker'))) {
            $this->components->error('The sandbox is in use. Run "php artisan db:use main" first.');

            return false;
        }

        $driver = $this->localConnection()['driver'] ?? null;

        if (! in_array($driver, ['mysql', 'mariadb'], strict: true)) {
            $this->components->error("The local connection is [{$driver}], and a production dump can only be imported into mysql.");

            return false;
        }

        return true;
    }

    /**
     * Read where production lives, from a single URL in the environment.
     *
     * Example: mysql://user:pass@host:3306/dbname?ssl-mode=REQUIRED
     *
     * @return array{host: string, port: string, database: string, username: string, password: ?string, query: array<string, string>}|null
     */
    protected function productionConnection(): ?array
    {
        $url = (string) config('database.production_url');

        if ($url === '') {
            $this->components->error('No production database is configured. Set DB_PRODUCTION_URL.');

            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['mysql', 'mariadb'], strict: true)) {
            $this->components->error('DB_PRODUCTION_URL must be a mysql:// or mariadb:// URL.');

            return null;
        }

        $database = ltrim($parts['path'] ?? '', '/');

        if ($database === '') {
            $this->components->error('DB_PRODUCTION_URL must name a database, as in mysql://user:pass@host:3306/dbname.');

            return null;
        }

        $query = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $parsed);

            foreach ($parsed as $key => $value) {
                if (is_string($value)) {
                    $query[(string) $key] = $value;
                }
            }
        }

        return [
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (string) ($parts['port'] ?? 3306),
            'database' => $database,
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? null,
            'query' => $query,
        ];
    }

    /**
     * Get a dump to import, reusing a recent one where there is one.
     */
    protected function takeDump(): bool
    {
        $existing = $this->option('force') ? null : $this->latestDump();

        if ($existing !== null) {
            $this->dumpFile = $existing;

            $this->components->info(sprintf(
                'Reusing %s, taken %s.',
                basename($existing),
                now()->createFromTimestamp(File::lastModified($existing))->diffForHumans(),
            ));

            return true;
        }

        $this->dumpFile = $this->dumpDirectory().'/production-'.now()->format('Y-m-d_H-i-s').'.sql';

        $arguments = [
            'mysqldump',
            ...$this->pluginArguments('mysqldump'),
            '--single-transaction',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--routines',
            '--triggers',
            '-h', $this->production['host'],
            '-P', $this->production['port'],
            '-u', $this->production['username'],
            ...$this->sslArguments(),
            $this->production['database'],
        ];

        $command = $this->shellCommand($arguments).' > '.escapeshellarg($this->dumpFile);

        $succeeded = $this->runProcess(
            $command,
            'Dumping production',
            $this->production['password'],
        );

        if ($succeeded && File::size($this->dumpFile) === 0) {
            $this->components->error('The dump came back empty.');
            $succeeded = false;
        }

        if (! $succeeded) {
            File::delete($this->dumpFile);

            return false;
        }

        $this->components->info(sprintf(
            'Dumped %s (%s MB).',
            basename($this->dumpFile),
            number_format(File::size($this->dumpFile) / 1048576, 1),
        ));

        $this->pruneOldDumps();

        return true;
    }

    /**
     * Load the dump into the local database.
     */
    protected function importDump(): bool
    {
        $connection = $this->localConnection();

        $arguments = [
            'mysql',
            ...$this->pluginArguments('mysql'),
            '-h', (string) $connection['host'],
            '-P', (string) ($connection['port'] ?? '3306'),
            '-u', (string) $connection['username'],
            (string) $connection['database'],
        ];

        $command = $this->shellCommand($arguments).' < '.escapeshellarg($this->dumpFile);

        return $this->runProcess($command, 'Importing the dump', $connection['password'] ?? null);
    }

    /**
     * Point a client at the authentication plugins that ship beside it.
     *
     * The MySQL clients are built to look for their plugins in the directory
     * the official tarball unpacks to, so an installation anywhere else, as
     * Herd's is, cannot load one. A server asking for mysql_native_password
     * then fails the connection outright.
     *
     * @return list<string>
     */
    protected function pluginArguments(string $binary): array
    {
        $path = (new ExecutableFinder)->find($binary);

        if ($path === null) {
            return [];
        }

        $directory = dirname((string) realpath($path), 2).'/lib/plugin';

        return is_dir($directory) ? ['--plugin-dir='.$directory] : [];
    }

    /**
     * Build the SSL options the production URL asks for, if any.
     *
     * Managed databases usually insist on a TLS connection, and say so in the
     * query string rather than in an option file.
     *
     * @return list<string>
     */
    protected function sslArguments(): array
    {
        $arguments = [];
        $mode = $this->production['query']['ssl-mode'] ?? $this->production['query']['sslmode'] ?? '';

        if ($mode !== '') {
            $arguments[] = '--ssl-mode='.strtoupper($mode);
        }

        foreach (['ssl-ca', 'ssl-cert', 'ssl-key'] as $option) {
            if (! empty($this->production['query'][$option])) {
                $arguments[] = "--{$option}=".$this->production['query'][$option];
            }
        }

        return $arguments;
    }

    /**
     * Run a shell command, passing any password through the environment so it
     * never appears in the process list.
     */
    protected function runProcess(string $command, string $description, ?string $password = null): bool
    {
        $environment = $password === null || $password === '' ? [] : ['MYSQL_PWD' => $password];

        $process = Process::fromShellCommandline($command, base_path(), $environment, null, 1200);

        $this->components->task($description, function () use ($process) {
            $process->run();

            return $process->isSuccessful();
        });

        if ($process->isSuccessful()) {
            return true;
        }

        $error = trim($process->getErrorOutput());

        $this->components->error($error === '' ? "{$description} failed." : $error);

        return false;
    }

    /**
     * Quote a list of command arguments into a single shell command.
     *
     * @param  list<string>  $arguments
     */
    protected function shellCommand(array $arguments): string
    {
        return implode(' ', array_map(escapeshellarg(...), $arguments));
    }

    /**
     * Find a dump that is recent enough to import again.
     */
    protected function latestDump(): ?string
    {
        $newest = collect($this->dumps())->first();

        if ($newest === null) {
            return null;
        }

        $age = (time() - File::lastModified($newest)) / 3600;

        return $age < $this->dumpLifetimeHours ? $newest : null;
    }

    /**
     * Delete all but the most recent few dumps.
     */
    protected function pruneOldDumps(): void
    {
        collect($this->dumps())
            ->slice($this->dumpsToKeep)
            ->each(fn (string $dump) => File::delete($dump));
    }

    /**
     * List the dumps on disk, newest first.
     *
     * @return list<string>
     */
    protected function dumps(): array
    {
        $dumps = collect(File::files($this->dumpDirectory()))
            ->filter(fn ($file) => preg_match('/^production-[\d_-]+\.sql$/', $file->getFilename()) === 1)
            ->filter(fn ($file) => $file->getSize() > 0)
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => $file->getPathname())
            ->values();

        return array_values($dumps->all());
    }

    /**
     * Get the directory dumps are kept in, creating it when it is new.
     *
     * Dumps live with the main checkout rather than the worktree that asked
     * for one, so a dump taken in one worktree can be reused by the next.
     */
    protected function dumpDirectory(): string
    {
        $directory = $this->mainCheckout().'/database/dumps';

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    /**
     * Resolve the root of the main checkout, even from inside a worktree.
     *
     * A worktree's .git is a file pointing at .git/worktrees/<name> in the
     * checkout it was made from, so the root is three levels above that.
     */
    protected function mainCheckout(): string
    {
        $git = base_path('.git');

        if (is_file($git) && preg_match('/^gitdir:\s*(.+)$/m', (string) File::get($git), $matches) === 1) {
            return dirname(trim($matches[1]), 3);
        }

        return base_path();
    }

    /**
     * Get the configuration behind the default connection.
     *
     * @return array<string, mixed>
     */
    protected function localConnection(): array
    {
        return (array) config('database.connections.'.config('database.default'));
    }
}

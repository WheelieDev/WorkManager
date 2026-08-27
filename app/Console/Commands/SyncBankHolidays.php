<?php

namespace App\Console\Commands;

use App\Models\BankHoliday;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class SyncBankHolidays extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bank-holidays:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync England and Wales bank holidays from gov.uk';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = config('flexi.bank_holidays_url');

        try {
            $response = Http::timeout(15)->retry(2, 200, throw: false)->get($url);
        } catch (ConnectionException $exception) {
            $this->components->error("Could not reach {$url}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->components->error("{$url} returned {$response->status()}.");

            return self::FAILURE;
        }

        $division = config('flexi.bank_holidays_division');
        $events = data_get($response->json(), "{$division}.events");

        if (! is_array($events) || $events === []) {
            $this->components->error("No events found for division [{$division}].");

            return self::FAILURE;
        }

        $synced = 0;

        foreach ($events as $event) {
            if (! isset($event['date'], $event['title'])) {
                continue;
            }

            BankHoliday::query()->updateOrCreate(
                ['date' => $event['date']],
                ['title' => $event['title'], 'notes' => $event['notes'] ?: null],
            );

            $synced++;
        }

        $this->components->info("Synced {$synced} bank holidays for {$division}.");

        return self::SUCCESS;
    }
}

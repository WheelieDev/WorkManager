# WorkManager

A personal work tracker: log your hours against a work pattern and keep an eye on your annual leave.

## What it does

- **Timesheet** — record work sessions and review them by day, week or period.
- **Work patterns** — define the days and months you work, so expected hours are worked out for you.
- **Leave** — book annual leave by picking dates, edit or cancel requests, and see the balance with the working out behind it. Bank holidays are tracked separately, with unspent public holiday hours credited back and carryover rolled into the next leave year.
- **Dashboard** — the current position at a glance.

## Stack

Laravel 13 on PHP 8.5, Livewire 4 with Flux UI, Tailwind via Vite, Pest for tests.

## Getting started

```bash
composer setup   # install dependencies, generate a key, migrate, build assets
composer dev     # serve the app with Vite and the queue running
```

The app is also served by Laravel Herd at https://work-manager.test.

## Checks

```bash
composer test          # Pint, PHPStan and the Pest suite
php artisan test       # tests only
composer lint          # apply Pint formatting
```

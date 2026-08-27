<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class SetPin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pin:set {pin? : The six-digit PIN, prompted for when omitted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the six-digit PIN used to sign in';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pin = $this->argument('pin');
        $isScripted = $pin !== null;

        if (! $isScripted) {
            $pin = password(
                label: 'Enter a six-digit PIN',
                validate: fn (string $value) => preg_match('/^\d{6}$/', $value) === 1
                    ? null
                    : 'The PIN must be exactly six digits.',
            );

            if (! hash_equals($pin, password(label: 'Confirm the PIN'))) {
                $this->components->error('The PINs did not match.');

                return self::FAILURE;
            }
        }

        if (preg_match('/^\d{6}$/', $pin) !== 1) {
            $this->components->error('The PIN must be exactly six digits.');

            return self::FAILURE;
        }

        $user = User::query()->first();

        if ($user === null) {
            $name = $isScripted
                ? 'Tom'
                : text(label: 'What is your name?', default: 'Tom', required: true);

            User::query()->create(['name' => $name, 'pin' => $pin]);

            $this->components->info('Account created and PIN set.');

            return self::SUCCESS;
        }

        $user->update(['pin' => $pin]);

        $this->components->info('PIN updated.');

        return self::SUCCESS;
    }
}

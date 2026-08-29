<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A setting entered in the application, overriding the config file.
 *
 * Config holds the defaults that ship with the code; anything set here is a
 * decision made by the person using it, so it wins.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    /**
     * Get a setting, falling back to the config file.
     */
    public static function read(string $key, mixed $fallback = null): mixed
    {
        return self::query()->where('key', $key)->value('value') ?? $fallback;
    }

    /**
     * Write a setting, or clear it back to the config file.
     */
    public static function write(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            self::query()->where('key', $key)->delete();

            return;
        }

        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

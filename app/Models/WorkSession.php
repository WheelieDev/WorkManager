<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkSessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['started_at', 'ended_at'])]
class WorkSession extends Model
{
    /** @use HasFactory<WorkSessionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Scope a query to sessions that have been stopped.
     *
     * @param  Builder<WorkSession>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->whereNotNull('ended_at');
    }

    /**
     * Scope a query to the session still running, if any.
     *
     * @param  Builder<WorkSession>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * Scope a query to sessions that started on the given date.
     *
     * A session is attributed to the date it started on, even if it runs past
     * midnight.
     *
     * @param  Builder<WorkSession>  $query
     */
    public function scopeStartedOn(Builder $query, DateTimeInterface $date): void
    {
        $query->whereBetween('started_at', [
            Carbon::parse($date)->startOfDay(),
            Carbon::parse($date)->endOfDay(),
        ]);
    }

    /**
     * Get the session currently running, if there is one.
     */
    public static function current(): ?self
    {
        return self::query()->running()->latest('started_at')->first();
    }

    /**
     * Start a new session, provided none is already running.
     *
     * @throws RuntimeException
     */
    public static function start(?DateTimeInterface $at = null): self
    {
        if (self::query()->running()->exists()) {
            throw new RuntimeException('A work session is already running.');
        }

        return self::query()->create([
            'started_at' => $at ? Carbon::parse($at) : now(),
        ]);
    }

    /**
     * Stop this session.
     *
     * @throws RuntimeException
     */
    public function stop(?DateTimeInterface $at = null): self
    {
        if (! $this->isRunning()) {
            throw new RuntimeException('This work session has already been stopped.');
        }

        $endedAt = $at ? Carbon::parse($at) : now();

        if ($endedAt->lessThanOrEqualTo($this->started_at)) {
            throw new RuntimeException('A work session must end after it starts.');
        }

        $this->update(['ended_at' => $endedAt]);

        return $this;
    }

    /**
     * Determine whether this session is still running.
     */
    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Get the date this session is attributed to.
     */
    public function date(): CarbonImmutable
    {
        return $this->started_at->startOfDay();
    }

    /**
     * Get the hours worked, or zero while the session is still running.
     */
    public function hours(): float
    {
        if ($this->isRunning()) {
            return 0.0;
        }

        return round($this->started_at->diffInMinutes($this->ended_at) / 60, 2);
    }

    /**
     * Get the hours elapsed so far, counting a running session up to now.
     */
    public function elapsedHours(): float
    {
        $end = $this->ended_at ?? now();

        return round($this->started_at->diffInMinutes($end) / 60, 2);
    }
}

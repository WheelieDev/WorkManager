<?php

namespace App\Models;

use App\Enums\WorkKind;
use Carbon\CarbonImmutable;
use Database\Factories\WorkSessionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property WorkKind $kind
 * @property int|null $work_area_id
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WorkArea|null $workArea
 */
#[Fillable(['started_at', 'ended_at', 'kind', 'work_area_id', 'note'])]
class WorkSession extends Model
{
    /** @use HasFactory<WorkSessionFactory> */
    use HasFactory;

    /**
     * The most a note may hold, in characters.
     *
     * Roomy rather than tight: a note on a long session is added to as the day
     * goes rather than written once at the end, and formatting it in Markdown
     * spends characters on syntax as well as words. The column behind it is
     * wider still, so this is the only limit that has to be thought about.
     */
    public const NoteLimit = 20000;

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
            'kind' => WorkKind::class,
        ];
    }

    /**
     * Get the area this session was booked to, if any.
     *
     * @return BelongsTo<WorkArea, $this>
     */
    public function workArea(): BelongsTo
    {
        return $this->belongsTo(WorkArea::class);
    }

    /**
     * Scope a query to sessions booked to one area.
     *
     * @param  Builder<WorkSession>  $query
     */
    public function scopeOfArea(Builder $query, WorkArea $area): void
    {
        $query->where('work_area_id', $area->id);
    }

    /**
     * Scope a query to sessions of one kind.
     *
     * @param  Builder<WorkSession>  $query
     */
    public function scopeOfKind(Builder $query, WorkKind $kind): void
    {
        $query->where('kind', $kind);
    }

    /**
     * Scope a query to the hours that settle in the flexi balance.
     *
     * @param  Builder<WorkSession>  $query
     */
    public function scopeCountingTowardsFlexi(Builder $query): void
    {
        $query->whereIn('kind', collect(WorkKind::cases())
            ->filter(fn (WorkKind $kind): bool => $kind->countsTowardsFlexi())
            ->map(fn (WorkKind $kind): string => $kind->value));
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
    public static function start(?DateTimeInterface $at = null, WorkKind $kind = WorkKind::Contracted, ?int $workAreaId = null): self
    {
        if (self::query()->running()->exists()) {
            throw new RuntimeException('A work session is already running.');
        }

        return self::query()->create([
            'started_at' => $at ? Carbon::parse($at) : now(),
            'kind' => $kind,
            'work_area_id' => $workAreaId,
        ]);
    }

    /**
     * Add a line to this session's note, keeping whatever is already there.
     *
     * Notes are written while the work is fresh, so each addition goes on its
     * own line rather than replacing the thought before it. Anything that
     * would overflow the limit is refused outright: silently truncating a note
     * would lose the very thing that was just written.
     *
     * @throws RuntimeException
     */
    public function appendNote(string $note): self
    {
        $note = trim($note);

        if ($note === '') {
            return $this;
        }

        $combined = $this->note === null ? $note : $this->note."\n".$note;

        if (mb_strlen($combined) > self::NoteLimit) {
            throw new RuntimeException('This note has no room left.');
        }

        $this->update(['note' => $combined]);

        return $this;
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
     * Determine whether this session is being worked back as time off later.
     */
    public function isLieu(): bool
    {
        return $this->kind === WorkKind::Lieu;
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

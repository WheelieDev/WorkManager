<?php

namespace App\Models;

use Database\Factories\WorkAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A strand of work that time can be booked against.
 *
 * Areas are a label rather than a budget: they say where the hours went, and
 * nothing about where they were meant to go.
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WorkSession> $sessions
 */
#[Fillable(['name', 'archived_at'])]
class WorkArea extends Model
{
    /** @use HasFactory<WorkAreaFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Get the sessions booked to this area.
     *
     * @return HasMany<WorkSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    /**
     * Scope a query to areas still being worked on.
     *
     * @param  Builder<WorkArea>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * Determine whether the area has been put away.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}

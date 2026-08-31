<?php

namespace App\Support;

use App\Models\WorkArea;
use App\Models\WorkSession;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Adds up the hours booked to each work area.
 *
 * Areas are a label rather than a budget, so this reports where the time went
 * and leaves any judgement about whether that was right to the person reading.
 */
class AreaTotals
{
    /**
     * Cached hours, keyed by area id.
     *
     * @var array<int, float>|null
     */
    protected ?array $totals = null;

    public function __construct(
        protected ?DateTimeInterface $from = null,
        protected ?DateTimeInterface $to = null,
    ) {}

    /**
     * Get the hours booked to every area, keyed by area id.
     *
     * Sessions with no area are left out: they have no area to be counted
     * against, and the timesheet already accounts for their hours.
     *
     * @return array<int, float>
     */
    public function all(): array
    {
        if ($this->totals !== null) {
            return $this->totals;
        }

        $query = WorkSession::query()
            ->completed()
            ->whereNotNull('work_area_id');

        if ($this->from !== null && $this->to !== null) {
            $query->whereBetween('started_at', [
                Carbon::parse($this->from)->startOfDay(),
                Carbon::parse($this->to)->endOfDay(),
            ]);
        }

        return $this->totals = $query->get()
            ->groupBy('work_area_id')
            ->map(fn (Collection $sessions): float => round(
                $sessions->sum(fn (WorkSession $session): float => $session->hours()),
                2,
            ))
            ->all();
    }

    /**
     * Get the hours booked to one area.
     */
    public function for(WorkArea $area): float
    {
        return $this->all()[$area->id] ?? 0.0;
    }

    /**
     * Get the total hours booked to any area.
     */
    public function total(): float
    {
        return round(array_sum($this->all()), 2);
    }
}

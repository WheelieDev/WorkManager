<?php

namespace App\Support;

/**
 * The occupational sick pay a length of service earns, in months of pay.
 */
class SicknessBand
{
    public function __construct(
        public readonly int $afterMonths,
        public readonly float $fullPayMonths,
        public readonly float $halfPayMonths,
    ) {}

    /**
     * Build a band from its configured shape.
     *
     * @param  array{after_months?: int|numeric-string, full_pay_months?: float|int|numeric-string, half_pay_months?: float|int|numeric-string}  $band
     */
    public static function fromConfig(array $band): self
    {
        return new self(
            (int) ($band['after_months'] ?? 0),
            (float) ($band['full_pay_months'] ?? 0),
            (float) ($band['half_pay_months'] ?? 0),
        );
    }

    /**
     * Describe the band the way the policy states it.
     */
    public function label(): string
    {
        $parts = [];

        if ($this->fullPayMonths > 0) {
            $parts[] = $this->months($this->fullPayMonths).' full pay';
        }

        if ($this->halfPayMonths > 0) {
            $parts[] = $this->months($this->halfPayMonths).' half pay';
        }

        return $parts === [] ? 'no occupational sick pay' : implode(' and ', $parts);
    }

    /**
     * Phrase a number of months.
     */
    protected function months(float $months): string
    {
        $rounded = round($months, 2);
        $whole = $rounded == (int) $rounded ? (string) (int) $rounded : (string) $rounded;

        return $whole.' '.($rounded == 1.0 ? 'month' : 'months');
    }
}

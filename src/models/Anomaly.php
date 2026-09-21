<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\enums\AnomalyType;

/**
 * Something that changed between one window of recorded activity and the window before it. It is a
 * comparison of two periods and nothing more — no trend is fitted and nothing is predicted.
 */
class Anomaly extends Model
{
    public AnomalyType $type = AnomalyType::ZeroResults;

    /** @var bool Whether the measurement went up. Only volume is worth reporting either way. */
    public bool $increased = true;

    /** @var float The measurement over the recent window: a rate, a count or milliseconds. */
    public float $recent = 0.0;

    /** @var float The same measurement over the window before it. */
    public float $baseline = 0.0;

    public int $recentSearches = 0;
    public int $baselineSearches = 0;

    /** @var int Days each window covers. Both windows are the same length, or nothing compares. */
    public int $windowDays = 0;

    public function getChange(): float
    {
        return $this->recent - $this->baseline;
    }

    /**
     * How much of the baseline the change was, or null when there was no baseline to compare with.
     */
    public function getRelativeChange(): ?float
    {
        return $this->baseline > 0 ? $this->getChange() / $this->baseline : null;
    }

    /**
     * What changed, in the units it was measured in. Every number here was recorded; none of them
     * is an estimate.
     */
    public function getDescription(): string
    {
        $days = $this->windowDays;

        return match ($this->type) {
            AnomalyType::ZeroResults => sprintf(
                'Searches finding nothing went from %s to %s of all searches over the last %d days.',
                $this->percentage($this->baseline),
                $this->percentage($this->recent),
                $days,
            ),
            AnomalyType::Volume => sprintf(
                'Searches went from %d to %d over the last %d days, %s %s.',
                (int)$this->baseline,
                (int)$this->recent,
                $days,
                $this->increased ? 'up' : 'down',
                $this->percentage(abs($this->getRelativeChange() ?? 0)),
            ),
            AnomalyType::ResponseTime => sprintf(
                'Searches took %s on average over the last %d days, against %s before.',
                $this->milliseconds($this->recent),
                $days,
                $this->milliseconds($this->baseline),
            ),
        };
    }

    private function percentage(float $rate): string
    {
        return round($rate * 100, 1) . '%';
    }

    private function milliseconds(float $time): string
    {
        return round($time) . 'ms';
    }
}

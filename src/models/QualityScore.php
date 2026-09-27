<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * How well search is serving people, out of 100, from three things that were actually recorded.
 *
 * Each part is a share of the searches it was measured over, so each is already between 0 and 1:
 *
 *   success     = 1 − (searches that found nothing ÷ searches)
 *   engagement  = searches where a result was opened ÷ searches whose index follows opened results
 *   speed       = 1 − (searches past their own index's slow threshold ÷ searches)
 *
 * The score is their weighted mean, ×100. The weights are a deliberate product judgement — finding
 * something at all matters most, answering quickly matters least — and nothing more than that: they
 * are not derived from data and no study stands behind them. They are stated here, and on the page,
 * so a score can always be taken apart into the numbers it came from.
 *
 * A part nothing can be known about is left out and the remaining weights are shared out again, so
 * an index that does not follow clicks is not scored as though nobody ever opened anything.
 */
class QualityScore extends Model
{
    /** @var float Finding something at all, which is what a search is for. */
    public const WEIGHT_SUCCESS = 0.5;

    /** @var float Finding something worth opening, which is the only evidence results were right. */
    public const WEIGHT_ENGAGEMENT = 0.3;

    /** @var float Answering quickly, which matters but never as much as answering at all. */
    public const WEIGHT_SPEED = 0.2;

    public int $searches = 0;

    /** @var float|null Share of searches that found something. */
    public ?float $success = null;

    /** @var float|null Share of searches a result was opened from, or null where none were followed. */
    public ?float $engagement = null;

    /**
     * @var int Searches engagement was measured over — those whose index follows opened results.
     * Fewer than `$searches` means the reading spans indexes that disagree about following them.
     */
    public int $engagementSearches = 0;

    /** @var float|null Share of searches inside the slow threshold of their own index. */
    public ?float $speed = null;

    /** @var float|null The weighted mean out of 100, or null when nothing was searched for. */
    public ?float $score = null;

    /**
     * @param InsightsSummary $summary Every search in the period.
     * @param InsightsSummary|null $clickTracked The searches whose index follows opened results,
     *                                           or null where no index in the reading does. This is
     *                                           engagement's own denominator: counting a search
     *                                           nobody was watching for clicks would report an
     *                                           index that records nothing as an index nobody uses.
     */
    public static function from(InsightsSummary $summary, ?InsightsSummary $clickTracked = null): self
    {
        $score = new self(['searches' => $summary->totalSearches]);

        if ($summary->totalSearches < 1) {
            return $score;
        }

        $score->success = 1 - $summary->getZeroResultRate();
        $score->speed = 1 - ($summary->slowSearches / $summary->totalSearches);
        $score->engagementSearches = $clickTracked !== null ? $clickTracked->totalSearches : 0;

        // A tracked subset with nothing in it measured nothing, which is not an engagement of zero.
        if ($clickTracked !== null && $clickTracked->totalSearches > 0) {
            $score->engagement = $clickTracked->getClickThroughRate();
        }

        $weighted = 0.0;
        $weights = 0.0;

        foreach ($score->getComponents() as $component) {
            $weighted += $component['value'] * $component['weight'];
            $weights += $component['weight'];
        }

        $score->score = $weights > 0 ? round(100 * $weighted / $weights, 1) : null;

        return $score;
    }

    /**
     * The parts that went into the score, each with the weight it carried.
     *
     * @return array<string,array{value:float,weight:float}>
     */
    public function getComponents(): array
    {
        $components = [];

        foreach ([
            'success' => self::WEIGHT_SUCCESS,
            'engagement' => self::WEIGHT_ENGAGEMENT,
            'speed' => self::WEIGHT_SPEED,
        ] as $name => $weight) {
            if ($this->$name !== null) {
                $components[$name] = ['value' => (float)$this->$name, 'weight' => $weight];
            }
        }

        return $components;
    }

    /**
     * Which of the three could not be measured, so a score is never read as though it covered them.
     *
     * @return string[]
     */
    public function getMissingComponents(): array
    {
        return array_values(array_diff(['success', 'engagement', 'speed'], array_keys($this->getComponents())));
    }

    /**
     * Whether engagement covers fewer searches than the rest of the score, which is what a reading
     * spanning indexes that disagree about following clicks has to say for itself.
     */
    public function engagementIsPartial(): bool
    {
        return $this->engagement !== null && $this->engagementSearches < $this->searches;
    }

    public function isScored(): bool
    {
        return $this->score !== null;
    }
}

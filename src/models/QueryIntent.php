<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\enums\SearchIntent;

/**
 * What a query appears to be after, and the words that said so. The cues are kept because they are
 * the whole of the evidence: nothing else was read, and nothing was inferred beyond them.
 */
class QueryIntent extends Model
{
    public SearchIntent $intent = SearchIntent::Unknown;

    /** @var array<string,string[]> Intent => the cues in the query that pointed at it. */
    public array $cues = [];

    /** @var bool Two intents were pointed at equally, so neither one is claimed. */
    public bool $ambiguous = false;

    /**
     * The cues behind the intent that was read, which is what makes a reading explainable.
     *
     * @return string[]
     */
    public function getMatchedCues(): array
    {
        return $this->cues[$this->intent->value] ?? [];
    }

    /**
     * Every intent anything pointed at, most cues first, which is what an ambiguous query has more
     * than one of.
     *
     * @return array<string,string[]>
     */
    public function getAllCues(): array
    {
        $cues = $this->cues;
        uasort($cues, static fn(array $a, array $b) => count($b) <=> count($a));

        return $cues;
    }

    /**
     * Why this query reads the way it does, in words. There is no score here: the evidence is the
     * cues, and claiming a confidence for them would be claiming an accuracy nobody has measured.
     */
    public function getExplanation(): string
    {
        if ($this->ambiguous) {
            $intents = array_map(
                static fn(string $intent) => SearchIntent::from($intent)->label(),
                array_keys($this->getAllCues()),
            );

            return 'Reads as both ' . implode(' and ', $intents) . ', so neither is claimed.';
        }

        if (!$this->intent->isKnown()) {
            return 'Nothing in the wording says what this query is after.';
        }

        $cues = array_map(static fn(string $cue) => '“' . $cue . '”', $this->getMatchedCues());

        return 'Read as ' . $this->intent->label() . ' from ' . implode(', ', $cues) . '.';
    }
}

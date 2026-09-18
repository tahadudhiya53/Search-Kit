<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTime;
use Tahadudhiya\SearchKit\enums\SynonymType;

/**
 * One group of words a search treats as the same thing. A two-way group's terms all stand in for
 * each other; a one-way group's terms expand into its replacements but never the other way around.
 */
class Synonym extends Model
{
    public ?int $id = null;

    /** @var int|null The only index this applies to, or null to apply to every index. */
    public ?int $indexId = null;

    /** @var int|null The only site this applies to, or null to apply in every site. */
    public ?int $siteId = null;

    public SynonymType $type = SynonymType::TwoWay;

    /** @var string[] The terms this group is triggered by, normalized. */
    public array $terms = [];

    /** @var string[] What a one-way group's terms also search for. Empty for a two-way group. */
    public array $replacements = [];

    public bool $enabled = true;
    public int $sortOrder = 0;

    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function isTwoWay(): bool
    {
        return $this->type === SynonymType::TwoWay;
    }

    /**
     * Whether this group governs a search of this index in this site.
     */
    public function applies(int $indexId, ?int $siteId): bool
    {
        if ($this->indexId !== null && $this->indexId !== $indexId) {
            return false;
        }

        return $this->siteId === null || $siteId === null || $this->siteId === $siteId;
    }

    /**
     * What else a term should be searched for, or nothing when this group does not cover it.
     *
     * @return string[]
     */
    public function expand(string $term): array
    {
        if (!$this->enabled || !in_array($term, $this->terms, true)) {
            return [];
        }

        $expansions = $this->isTwoWay()
            ? array_values(array_diff($this->terms, [$term]))
            : $this->replacements;

        return array_values(array_unique($expansions));
    }

    /**
     * The group as a single line, which is how it reads in a list and in a form.
     */
    public function __toString(): string
    {
        $terms = implode(', ', $this->terms);

        return $this->isTwoWay() ? $terms : $terms . ' → ' . implode(', ', $this->replacements);
    }

    protected function defineRules(): array
    {
        return [
            // An empty list is exactly what needs rejecting, so neither is skipped for it.
            [['terms'], 'validateTerms', 'skipOnEmpty' => false],
            [['replacements'], 'validateReplacements', 'skipOnEmpty' => false],
            [['sortOrder'], 'integer', 'min' => 0],
            [['id', 'indexId', 'siteId'], 'integer', 'min' => 1],
        ];
    }

    public function validateTerms(string $attribute): void
    {
        if (!$this->wordsAreUsable($attribute, $this->terms)) {
            return;
        }

        if ($this->terms === []) {
            $this->addError($attribute, 'A synonym needs at least one term.');
            return;
        }

        // A two-way group of one term would say a word is a synonym of itself.
        if ($this->isTwoWay() && count(array_unique($this->terms)) < 2) {
            $this->addError($attribute, 'A two-way synonym needs at least two different terms.');
        }
    }

    public function validateReplacements(string $attribute): void
    {
        if (!$this->wordsAreUsable($attribute, $this->replacements)) {
            return;
        }

        if ($this->isTwoWay() && $this->replacements !== []) {
            $this->addError($attribute, 'A two-way synonym has no replacements: its terms replace each other.');
            return;
        }

        if (!$this->isTwoWay() && $this->replacements === []) {
            $this->addError($attribute, 'A one-way synonym needs at least one replacement.');
        }
    }

    /**
     * @param string[] $words
     */
    private function wordsAreUsable(string $attribute, array $words): bool
    {
        foreach ($words as $word) {
            // Normalization has already run by the time these are saved, so anything empty here is
            // a word that reduced to nothing at all and could never be matched.
            if (trim($word) === '') {
                $this->addError($attribute, 'Every word must be searchable text.');
                return false;
            }
        }

        return true;
    }
}

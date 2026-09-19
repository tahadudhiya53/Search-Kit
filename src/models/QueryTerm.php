<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use Tahadudhiya\SearchKit\enums\PartialMatchMode;

/**
 * One term of a parsed query. Everything a term can mean is expressed here, so a provider never
 * has to read search syntax of its own.
 */
class QueryTerm extends Model
{
    /** @var string The normalized term, or the whole phrase when this term is one. */
    public string $text = '';

    /** @var string What the user typed, kept so a correction can be explained. */
    public string $original = '';

    /** @var bool Whether matching this term rules a result out rather than in. */
    public bool $excluded = false;

    /** @var bool Whether the words in the text have to appear together, in order. */
    public bool $phrase = false;

    /** @var bool Whether the text is a correction of what was typed rather than the text itself. */
    public bool $corrected = false;

    /** @var bool Whether the term was written to match a whole word and nothing longer. */
    public bool $exact = false;

    /** @var bool Whether the partial matching below was asked for with a `*` rather than configured. */
    public bool $wildcard = false;

    public PartialMatchMode $partial = PartialMatchMode::Off;

    /**
     * @var self[] Equally acceptable terms: what `OR` joined to this one, and its synonyms. Each
     * carries its own matching, and none of them may be an exclusion — a term cannot both rule a
     * result in and rule it out.
     */
    private array $_alternatives = [];

    public static function make(string $text, ?string $original = null): self
    {
        return new self([
            'text' => $text,
            'original' => $original ?? $text,
        ]);
    }

    /**
     * This term and everything else that satisfies it, the term itself first.
     *
     * @return self[]
     */
    public function getVariants(): array
    {
        return [$this, ...$this->_alternatives];
    }

    /**
     * @return self[]
     */
    public function getAlternatives(): array
    {
        return $this->_alternatives;
    }

    /**
     * The texts that satisfy this term, which is what an excerpt is marked from.
     *
     * @return string[]
     */
    public function getTexts(): array
    {
        return array_values(array_unique(array_map(static fn(self $term) => $term->text, $this->getVariants())));
    }

    /**
     * Adds another term this one accepts instead. An exclusion is never an alternative, and neither
     * is a repeat of something already accepted.
     */
    public function addAlternative(self $alternative): void
    {
        if ($alternative->text === '' || $alternative->excluded || in_array($alternative->text, $this->getTexts(), true)) {
            return;
        }

        $this->_alternatives[] = $alternative;
    }

    /**
     * Adds plain words this term also accepts, matched exactly as this one is. This is how a
     * configured synonym joins a term: it stands in for the word, so it is looked for the same way.
     */
    public function addSynonyms(string ...$texts): void
    {
        foreach ($texts as $text) {
            $synonym = self::make($text);
            $synonym->partial = $this->partial;
            $synonym->phrase = str_contains($text, ' ');

            $this->addAlternative($synonym);
        }
    }

    public function hasAlternatives(): bool
    {
        return $this->_alternatives !== [];
    }

    /**
     * Alternatives are terms of their own, so a copy of a term has to be a copy of them too.
     */
    public function __clone(): void
    {
        $this->_alternatives = array_map(static fn(self $term) => clone $term, $this->_alternatives);
    }

    /**
     * The words of this term, which for a phrase is more than one.
     *
     * @return string[]
     */
    public function getWords(): array
    {
        return preg_split('/\s+/u', $this->text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * The term written the way it was parsed, so a corrected query can be shown back to a user.
     */
    public function __toString(): string
    {
        $written = [];

        // Written back out the way it was typed, so a corrected query reads like a query. How the
        // index matches is not part of that: nobody wrote those asterisks.
        foreach ($this->getVariants() as $variant) {
            $text = $variant->phrase || $variant->exact ? '"' . $variant->text . '"' : $variant->text;

            $written[] = match ($variant->wildcard ? $variant->partial : PartialMatchMode::Off) {
                PartialMatchMode::Substring => '*' . $text . '*',
                PartialMatchMode::Prefix => $text . '*',
                PartialMatchMode::Off => $text,
            };
        }

        return ($this->excluded ? '-' : '') . implode(' OR ', $written);
    }
}

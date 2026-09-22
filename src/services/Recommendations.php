<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\helpers\UrlHelper;
use Tahadudhiya\SearchKit\enums\RecommendationType;
use Tahadudhiya\SearchKit\models\ClickedResult;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\QueryInsight;
use Tahadudhiya\SearchKit\models\Recommendation;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SynonymCandidate;
use Tahadudhiya\SearchKit\SearchKit;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * What somebody could do about what the recorded activity shows. Each recommendation carries the
 * measurements it was read from, because those are the argument for it — and the argument is the
 * only reason to act on one.
 */
class Recommendations extends Component
{
    private ?Intelligence $_intelligence = null;

    /**
     * Everything worth doing about the period, the most-searched first. Nothing is applied: each
     * one names a page where an administrator can decide.
     *
     * @param SearchIndex|null $index The index the period describes, where it describes one. Given
     *                                one, a synonym an existing group already covers is not offered.
     * @return Recommendation[]
     */
    public function forCriteria(InsightsCriteria $criteria, ?SearchIndex $index = null): array
    {
        $recommendations = [
            ...$this->fromGaps($this->getIntelligence()->getContentGaps($criteria)),
            ...$this->fromSynonymCandidates($this->getIntelligence()->discoverSynonyms($criteria, $index)),
            ...$this->fromDeepClicks($this->getIntelligence()->getDeepClicks($criteria)),
        ];

        usort(
            $recommendations,
            static fn(Recommendation $a, Recommendation $b) => [$b->searches, $a->subject] <=> [$a->searches, $b->subject],
        );

        return array_slice($recommendations, 0, $criteria->limit);
    }

    /**
     * A query people search for and nothing comes of. Which of the two things to do about it
     * depends on whether there was anything to open in the first place.
     *
     * @param QueryInsight[] $gaps
     * @return Recommendation[]
     */
    private function fromGaps(array $gaps): array
    {
        $recommendations = [];

        foreach ($gaps as $gap) {
            $findsNothing = $gap->getZeroResultRate() >= Intelligence::GAP_ZERO_RESULT_RATE;

            $recommendations[] = new Recommendation([
                'type' => $findsNothing ? RecommendationType::ImproveContent : RecommendationType::CreateRule,
                'subject' => $gap->query,
                'searches' => $gap->searches,
                'reason' => $findsNothing
                    ? sprintf(
                        'Searched for %d times, and %s of those searches found nothing at all. There is demand here that the content does not answer.',
                        $gap->searches,
                        $this->percentage($gap->getZeroResultRate()),
                    )
                    : sprintf(
                        'Searched for %d times. Results came back every time and nobody opened one, so what is being found is not what is being looked for.',
                        $gap->searches,
                    ),
                'evidence' => [
                    'Searches' => (string)$gap->searches,
                    'Found nothing' => $this->percentage($gap->getZeroResultRate()),
                    'Opened a result' => $this->percentage($gap->getClickThroughRate()),
                ],
                // Nothing to click for missing content; a wrong result is a rule to write.
                'url' => $findsNothing ? null : UrlHelper::cpUrl('search-kit/rules/new'),
            ]);
        }

        return $recommendations;
    }

    /**
     * @param SynonymCandidate[] $candidates
     * @return Recommendation[]
     */
    private function fromSynonymCandidates(array $candidates): array
    {
        $recommendations = [];

        foreach ($candidates as $candidate) {
            $recommendations[] = new Recommendation([
                'type' => RecommendationType::CreateSynonym,
                'subject' => implode(', ', $candidate->getTerms()),
                'searches' => $candidate->getSearches(),
                'reason' => $candidate->getEvidence()
                    . ' Treating them as the same thing would let either wording find all of it.'
                    . ($candidate->isSiteSpecific()
                        // The scope both were searched under is the scope a group belongs in.
                        ? ' Both were searched in one site, so a group written for it belongs there.'
                        : ''),
                'evidence' => [
                    'Searches' => (string)$candidate->getSearches(),
                    'Shared results' => (string)$candidate->sharedResults,
                    'Overlap' => $this->percentage($candidate->getOverlap()),
                    'Observed in' => $candidate->isSiteSpecific()
                        ? $this->siteName($candidate->siteId)
                        : 'Searches of every site the index covers',
                ],
                'url' => UrlHelper::cpUrl('search-kit/synonyms/new'),
            ]);
        }

        return $recommendations;
    }

    /**
     * A result people reach only by scrolling, which is a result that belongs higher up.
     *
     * @param ClickedResult[] $results
     * @return Recommendation[]
     */
    private function fromDeepClicks(array $results): array
    {
        $recommendations = [];

        foreach ($results as $result) {
            $recommendations[] = new Recommendation([
                'type' => RecommendationType::PromoteResult,
                'subject' => (string)$result->query,
                'searches' => $result->clicks,
                'reason' => sprintf(
                    'Searching for “%s”, people opened %s — but on average it sat at position %s, so they scrolled past everything above it.',
                    (string)$result->query,
                    $result->isResolved() ? '“' . $result->label . '”' : 'the same result',
                    (string)$result->averagePosition,
                ),
                'evidence' => [
                    'Times opened' => (string)$result->clicks,
                    'Average position' => (string)$result->averagePosition,
                    'Result' => $result->isResolved() ? (string)$result->label : 'No longer readable',
                ],
                'url' => UrlHelper::cpUrl('search-kit/rules/new'),
            ]);
        }

        return $recommendations;
    }

    private function percentage(float $rate): string
    {
        return round($rate * 100, 1) . '%';
    }

    /**
     * The site a pair was observed in, by name. A site that has since gone keeps its ID rather than
     * losing the scope the evidence belongs to.
     */
    private function siteName(?int $siteId): string
    {
        $site = $siteId !== null ? Craft::$app->getSites()->getSiteById($siteId, true) : null;

        return $site !== null ? $site->name : 'Site ' . $siteId;
    }

    public function setIntelligence(Intelligence $intelligence): void
    {
        $this->_intelligence = $intelligence;
    }

    public function getIntelligence(): Intelligence
    {
        return $this->_intelligence ??= SearchKit::getInstance()?->getIntelligence()
            ?? throw new InvalidConfigException('Search Kit is not installed or is disabled.');
    }
}

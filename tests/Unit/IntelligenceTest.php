<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\AnomalyType;
use Tahadudhiya\SearchKit\models\Anomaly;
use Tahadudhiya\SearchKit\models\InsightsSummary;
use Tahadudhiya\SearchKit\models\QualityScore;
use Tahadudhiya\SearchKit\models\SynonymCandidate;
use Tahadudhiya\SearchKit\services\Intelligence;

/**
 * The calculations behind the readings: the quality score, what counts as a change worth reporting,
 * and what two queries have to have in common before they look like the same thing.
 */
class IntelligenceTest extends TestCase
{
    public function testTheScoreIsTheWeightedMeanOfWhatWasMeasured(): void
    {
        // 100 searches: 10 found nothing, 50 led to a click, 20 were slow.
        $summary = $this->summary(100, 10, 50, 20);
        $score = QualityScore::from($summary, $summary);

        self::assertSame(0.9, $score->success);
        self::assertSame(0.5, $score->engagement);
        self::assertSame(0.8, $score->speed);

        // (0.9 × 0.5) + (0.5 × 0.3) + (0.8 × 0.2) = 0.76, over weights summing to 1.
        self::assertSame(76.0, $score->score);
    }

    public function testAPartNothingWasRecordedAboutIsLeftOutRatherThanScoredAsZero(): void
    {
        $summary = $this->summary(100, 10, 0, 20);
        $untracked = QualityScore::from($summary);
        $tracked = QualityScore::from($summary, $summary);

        self::assertNull($untracked->engagement);
        self::assertSame(['engagement'], $untracked->getMissingComponents());

        // (0.9 × 0.5) + (0.8 × 0.2) = 0.61, over the 0.7 of weight that was measurable.
        self::assertSame(87.1, $untracked->score);
        self::assertSame(61.0, $tracked->score, 'Scored as zero engagement, the same index reads far worse.');
    }

    public function testEngagementIsMeasuredOverTheSearchesItCouldHaveBeenMeasuredOver(): void
    {
        // 100 searches across two indexes; only the 40 belonging to one of them follow clicks, and
        // 20 of those led to something being opened.
        $everything = $this->summary(100, 10, 20, 20);
        $tracked = $this->summary(40, 4, 20, 8);

        $score = QualityScore::from($everything, $tracked);

        self::assertSame(0.5, $score->engagement, 'Engagement is 20 of the 40 that were watched.');
        self::assertSame(40, $score->engagementSearches);
        self::assertTrue($score->engagementIsPartial(), 'A partial engagement has to say so.');

        // Read over all 100 searches it would have been 0.2, marking the untracked index down for
        // clicks nobody was ever recording.
        self::assertNotSame(0.2, $score->engagement);

        // Success and speed still cover the whole period: they need nothing to have been tracked.
        self::assertSame(100, $score->searches);
        self::assertSame(0.9, $score->success);
        self::assertSame(0.8, $score->speed);
    }

    public function testEngagementCoveringEveryIndexIsNotReportedAsPartial(): void
    {
        $summary = $this->summary(100, 10, 50, 20);
        $score = QualityScore::from($summary, $summary);

        self::assertSame(100, $score->engagementSearches);
        self::assertFalse($score->engagementIsPartial());
    }

    public function testATrackedSubsetWithNoSearchesInItLeavesEngagementUnmeasured(): void
    {
        $score = QualityScore::from($this->summary(100, 10, 0, 20), $this->summary(0, 0, 0, 0));

        self::assertNull($score->engagement);
        self::assertSame(['engagement'], $score->getMissingComponents());
        self::assertFalse($score->engagementIsPartial(), 'Nothing measured is not a partial measurement.');
    }

    public function testNothingSearchedForIsNotAScoreOfZero(): void
    {
        $score = QualityScore::from($this->summary(0, 0, 0, 0), $this->summary(0, 0, 0, 0));

        self::assertFalse($score->isScored());
        self::assertNull($score->score);
    }

    public function testAPeriodWithoutABaselineWorthTheNameIsNotComparedAtAll(): void
    {
        $anomalies = (new Intelligence())->compare(
            $this->summary(100, 90, 0, 0),
            $this->summary(5, 0, 0, 0),
            500,
        );

        self::assertSame([], $anomalies, 'A handful of searches is not something to measure against.');
    }

    public function testASuddenRiseInSearchesFindingNothingIsReported(): void
    {
        $anomalies = (new Intelligence())->compare(
            $this->summary(100, 40, 0, 0),
            $this->summary(100, 5, 0, 0),
            500,
        );

        self::assertCount(1, $anomalies);
        self::assertSame(AnomalyType::ZeroResults, $anomalies[0]->type);
        self::assertSame(0.4, $anomalies[0]->recent);
        self::assertStringContainsString('40%', $anomalies[0]->getDescription());
    }

    public function testASmallRiseFromNearlyNothingIsNotReported(): void
    {
        $anomalies = (new Intelligence())->compare(
            $this->summary(100, 10, 0, 0),
            $this->summary(100, 2, 0, 0),
            500,
        );

        self::assertSame([], $anomalies, 'A rise that leaves the rate low is not worth an alert.');
    }

    public function testSearchStoppingAltogetherIsReportedEvenThoughThereIsNothingLeftToMeasure(): void
    {
        $anomalies = (new Intelligence())->compare(
            $this->summary(0, 0, 0, 0),
            $this->summary(100, 5, 50, 0),
            500,
        );

        self::assertCount(1, $anomalies);
        self::assertSame(AnomalyType::Volume, $anomalies[0]->type);
        self::assertFalse($anomalies[0]->increased);
        self::assertSame(-1.0, $anomalies[0]->getRelativeChange());
    }

    public function testAVolumeChangeHasToBeBigInBothWaysOfCountingIt(): void
    {
        $intelligence = new Intelligence();

        // Doubled, but only by 15 searches, which is not enough to be sure of.
        self::assertSame([], $intelligence->compare($this->summary(45, 0, 0, 0), $this->summary(30, 0, 0, 0), 500));

        // A big number of searches, but a small share of a much bigger baseline.
        self::assertSame([], $intelligence->compare($this->summary(1040, 0, 0, 0), $this->summary(1000, 0, 0, 0), 500));
    }

    public function testAResponseTimeIsOnlyReportedOnceItIsBothMuchWorseAndActuallySlow(): void
    {
        $intelligence = new Intelligence();

        $slower = $intelligence->compare(
            $this->summary(100, 0, 0, 0, 900.0),
            $this->summary(100, 0, 0, 0, 200.0),
            500,
        );

        self::assertCount(1, $slower);
        self::assertSame(AnomalyType::ResponseTime, $slower[0]->type);

        // Four times worse, and still comfortably inside what this index calls slow.
        self::assertSame([], $intelligence->compare(
            $this->summary(100, 0, 0, 0, 40.0),
            $this->summary(100, 0, 0, 0, 10.0),
            500,
        ));
    }

    public function testTwoQueriesOpeningTheSameResultsArePairedAndTheirEvidenceKept(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1], [3, 1]]),
                ...$this->opened('couch', [[1, 1], [2, 1]]),
            ],
            $this->counts(['sofa' => 20, 'couch' => 8]),
        );

        self::assertCount(1, $candidates);
        self::assertSame(['couch', 'sofa'], $candidates[0]->getTerms());
        self::assertSame(2, $candidates[0]->sharedResults);
        self::assertSame(1.0, $candidates[0]->getOverlap(), 'Measured against the narrower query.');
        self::assertSame(28, $candidates[0]->getSearches());
        self::assertStringContainsString('share 2 of the results', $candidates[0]->getEvidence());
    }

    public function testOneResultInCommonIsNotEvidenceOfAnything(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1]]),
                ...$this->opened('lamp', [[1, 1], [9, 1]]),
            ],
            $this->counts(['sofa' => 20, 'lamp' => 20]),
        );

        self::assertSame([], $candidates);
    }

    public function testTheSameElementInAnotherSiteIsAnotherResult(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1]]),
                ...$this->opened('couch', [[1, 3], [2, 3]]),
            ],
            $this->counts(['sofa' => 20, 'couch' => 20]),
        );

        self::assertSame([], $candidates, 'Two sites’ versions of a result are not the same result.');
    }

    public function testAQueryNobodySearchedForOftenEnoughIsNeverPaired(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1]]),
                ...$this->opened('couch', [[1, 1], [2, 1]]),
            ],
            // “couch” did not meet the minimum, so it never reached the counts at all.
            $this->counts(['sofa' => 20]),
        );

        self::assertSame([], $candidates);
    }

    public function testThePairsWithTheMostInCommonComeFirst(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1], [3, 1], [4, 1]]),
                ...$this->opened('couch', [[1, 1], [2, 1], [3, 1], [4, 1]]),
                ...$this->opened('settee', [[1, 1], [2, 1], [8, 1], [9, 1]]),
            ],
            $this->counts(['sofa' => 30, 'couch' => 20, 'settee' => 10]),
        );

        self::assertSame(1.0, $candidates[0]->getOverlap());
        self::assertSame(['couch', 'sofa'], $candidates[0]->getTerms());
        self::assertGreaterThanOrEqual(
            $candidates[count($candidates) - 1]->getOverlap(),
            $candidates[0]->getOverlap(),
        );
    }

    public function testTwoQueriesSearchedInDifferentSitesAreNeverPaired(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                // The same result opened from both, but the searches were made in different sites,
                // which on a multi-site index means different languages.
                ...$this->opened('sofa', [[1, 1], [2, 1]], searchedIn: 1),
                ...$this->opened('sofa', [[1, 1], [2, 1]], searchedIn: 3),
                ...$this->opened('couch', [[1, 1], [2, 1]], searchedIn: 3),
            ],
            $this->counts(['sofa' => 20], 1) + $this->counts(['sofa' => 20, 'couch' => 20], 3),
        );

        self::assertCount(1, $candidates, 'Only the pair searched under one scope is evidence.');
        self::assertSame(['couch', 'sofa'], $candidates[0]->getTerms());
        self::assertSame(3, $candidates[0]->siteId);
        self::assertTrue($candidates[0]->isSiteSpecific());
    }

    public function testAPairSearchedAcrossTheWholeScopeNamesNoSite(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1]]),
                ...$this->opened('couch', [[1, 1], [2, 1]]),
            ],
            $this->counts(['sofa' => 20, 'couch' => 20]),
        );

        self::assertCount(1, $candidates);
        self::assertNull($candidates[0]->siteId);
        self::assertFalse(
            $candidates[0]->isSiteSpecific(),
            'A pair from searches covering every site does not name one to write a group for.',
        );
    }

    public function testAQuerySearchedInOneSiteIsNotPairedWithTheSameWordingSearchedAcrossAllOfThem(): void
    {
        $candidates = (new Intelligence())->pair(
            [
                ...$this->opened('sofa', [[1, 1], [2, 1]], searchedIn: 1),
                ...$this->opened('couch', [[1, 1], [2, 1]]),
            ],
            $this->counts(['sofa' => 20], 1) + $this->counts(['couch' => 20]),
        );

        self::assertSame([], $candidates, 'A search of one site and a search of every site are different scopes.');
    }

    public function testACandidateWithNothingOpenedHasNoOverlapRatherThanDividingByNothing(): void
    {
        self::assertSame(0.0, (new SynonymCandidate())->getOverlap());
    }

    public function testAnAnomalyDescribesItselfInTheUnitsItWasMeasuredIn(): void
    {
        $volume = new Anomaly([
            'type' => AnomalyType::Volume,
            'increased' => true,
            'recent' => 300.0,
            'baseline' => 100.0,
            'windowDays' => 7,
        ]);

        self::assertStringContainsString('from 100 to 300', $volume->getDescription());
        self::assertStringContainsString('up 200%', $volume->getDescription());
    }

    /**
     * Clicked results as the discovery reading hands them over: one row per query, the site the
     * search was recorded for, and the result opened.
     *
     * @param array<int,array{0:int,1:int}> $results Element and site.
     * @return array<int,array<string,mixed>>
     */
    private function opened(string $query, array $results, ?int $searchedIn = null): array
    {
        return array_map(static fn(array $result) => [
            'normalizedQuery' => $query,
            'eventSiteId' => $searchedIn,
            'elementId' => $result[0],
            'clickSiteId' => $result[1],
        ], $results);
    }

    /**
     * Search counts as the discovery reading hands them over, keyed by scope and query.
     *
     * @param array<string,int> $counts
     * @return array<string,int>
     */
    private function counts(array $counts, ?int $searchedIn = null): array
    {
        $keyed = [];

        foreach ($counts as $query => $searches) {
            $keyed[Intelligence::bucket($searchedIn, (string)$query)] = $searches;
        }

        return $keyed;
    }

    private function summary(
        int $searches,
        int $zeroResults,
        int $clickedSearches,
        int $slow,
        float $time = 0.0,
    ): InsightsSummary {
        return new InsightsSummary([
            'totalSearches' => $searches,
            'zeroResultSearches' => $zeroResults,
            'clickedSearches' => $clickedSearches,
            'clicks' => $clickedSearches,
            'slowSearches' => $slow,
            'averageResponseTime' => $time,
        ]);
    }
}

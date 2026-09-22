<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateTime;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\enums\AnomalyType;
use Tahadudhiya\SearchKit\enums\RecommendationType;
use Tahadudhiya\SearchKit\enums\SearchIntent;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\AnalyticsSettings;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\Recommendation;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\services\Insights;
use Tahadudhiya\SearchKit\services\Intelligence;
use yii\db\Expression;

/**
 * What real recorded activity is read as: how well search is doing, what changed, what people
 * cannot find, and what an administrator is told to do about it.
 */
class SearchIntelligenceTest extends SearchContentTestCase
{
    public function testTheQualityScoreIsTheWeightedMeanOfWhatWasActuallyRecorded(): void
    {
        $index = $this->measuredIndex();

        $found = $this->record($index, 'zqxfound', results: 3, times: 8);
        $this->record($index, 'zqxmissing', results: 0, times: 2);

        // Five of the ten searches led to a result being opened.
        foreach (array_slice($found, 0, 5) as $eventId) {
            $this->open($eventId, 101, $this->fieldSectionSiteId());
        }

        $score = $this->intelligence()->getQualityScore($this->criteria($index));

        self::assertSame(10, $score->searches);
        self::assertSame(0.8, $score->success);
        self::assertSame(0.5, $score->engagement);
        self::assertSame(1.0, $score->speed);

        // (0.8 × 0.5) + (0.5 × 0.3) + (1.0 × 0.2) = 0.75.
        self::assertSame(75.0, $score->score);
    }

    public function testAnIndexThatFollowsNoClicksIsNotScoredAsThoughNobodyOpenedAnything(): void
    {
        $index = $this->measuredIndex(new AnalyticsSettings(['trackClicks' => false]));
        $this->record($index, 'zqxfound', results: 3, times: 10);

        $score = $this->intelligence()->getQualityScore($this->criteria($index));

        self::assertNull($score->engagement);
        self::assertSame(['engagement'], $score->getMissingComponents());
        self::assertSame(100.0, $score->score, 'Everything that could be measured was perfect.');
    }

    public function testASuddenRiseInSearchesFindingNothingIsReportedAgainstTheWindowBefore(): void
    {
        $index = $this->measuredIndex();

        $this->record($index, 'zqxbaseline', results: 3, times: 30, modifier: '-10 days');
        $this->record($index, 'zqxrecent', results: 3, times: 10, modifier: '-2 days');
        $this->record($index, 'zqxbroken', results: 0, times: 20, modifier: '-2 days');

        $anomalies = $this->intelligence()->detectAnomalies($this->criteria($index));
        $types = array_map(static fn($anomaly) => $anomaly->type, $anomalies);

        self::assertContains(AnomalyType::ZeroResults, $types);

        foreach ($anomalies as $anomaly) {
            if ($anomaly->type === AnomalyType::ZeroResults) {
                self::assertEqualsWithDelta(20 / 30, $anomaly->recent, 0.001);
                self::assertSame(0.0, $anomaly->baseline);
                self::assertSame(Intelligence::WINDOW_DAYS, $anomaly->windowDays);
            }
        }
    }

    public function testAQuietPeriodIsNotReportedAsAnAnomaly(): void
    {
        $index = $this->measuredIndex();

        // Too few searches either side to measure anything from.
        $this->record($index, 'zqxquiet', results: 0, times: 3, modifier: '-10 days');
        $this->record($index, 'zqxquiet', results: 0, times: 3, modifier: '-2 days');

        self::assertSame([], $this->intelligence()->detectAnomalies($this->criteria($index)));
    }

    public function testTwoQueriesPeopleOpenTheSameResultsFromAreOfferedAsASynonym(): void
    {
        $index = $this->measuredIndex();
        $siteId = $this->fieldSectionSiteId();

        foreach ($this->record($index, 'zqxsofa', results: 4, times: 3) as $eventId) {
            $this->open($eventId, 201, $siteId);
            $this->open($eventId, 202, $siteId, 2);
        }

        foreach ($this->record($index, 'zqxcouch', results: 4, times: 3) as $eventId) {
            $this->open($eventId, 201, $siteId);
            $this->open($eventId, 202, $siteId, 2);
        }

        $candidates = $this->intelligence()->discoverSynonyms($this->criteria($index), $index);

        self::assertCount(1, $candidates);
        self::assertSame(['zqxcouch', 'zqxsofa'], $candidates[0]->getTerms());
        self::assertSame(2, $candidates[0]->sharedResults);
        self::assertSame(1.0, $candidates[0]->getOverlap());
    }

    public function testAPairAnExistingSynonymGroupAlreadyCoversIsNothingToRecommend(): void
    {
        $index = $this->measuredIndex();
        $siteId = $this->fieldSectionSiteId();

        foreach (['zqxsofa', 'zqxcouch'] as $query) {
            foreach ($this->record($index, $query, results: 4, times: 3) as $eventId) {
                $this->open($eventId, 201, $siteId);
                $this->open($eventId, 202, $siteId, 2);
            }
        }

        self::assertCount(1, $this->intelligence()->discoverSynonyms($this->criteria($index), $index));

        $synonym = new Synonym([
            'indexId' => $index->id,
            'type' => SynonymType::TwoWay,
            'terms' => ['zqxsofa', 'zqxcouch'],
        ]);

        self::assertTrue($this->plugin()->getSynonyms()->saveSynonym($synonym));

        try {
            self::assertSame(
                [],
                $this->intelligence()->discoverSynonyms($this->criteria($index), $index),
                'There is nothing to suggest about two words a group already treats as one.',
            );
        } finally {
            $this->plugin()->getSynonyms()->deleteSynonym($synonym);
        }
    }

    public function testAPopularQueryThatFindsNothingIsAGapAndRecommendsContentRatherThanARule(): void
    {
        $index = $this->measuredIndex();
        $this->record($index, 'zqxnothinghere', results: 0, times: 6);

        $gaps = $this->intelligence()->getContentGaps($this->criteria($index));

        self::assertSame(['zqxnothinghere'], array_map(static fn($gap) => $gap->query, $gaps));

        $recommendation = $this->recommendationFor($index, 'zqxnothinghere');

        self::assertSame(RecommendationType::ImproveContent, $recommendation->type);
        self::assertNull($recommendation->url, 'There is no page that writes the missing content.');
        self::assertStringContainsString('6 times', $recommendation->reason);
        self::assertSame('100%', $recommendation->evidence['Found nothing']);
    }

    public function testAPopularQueryWhoseResultsNobodyOpensRecommendsARule(): void
    {
        $index = $this->measuredIndex();
        $this->record($index, 'zqxwrongresults', results: 12, times: 6);

        $recommendation = $this->recommendationFor($index, 'zqxwrongresults');

        self::assertSame(RecommendationType::CreateRule, $recommendation->type);
        self::assertNotNull($recommendation->url);
        self::assertSame('0%', $recommendation->evidence['Opened a result']);
    }

    public function testAResultPeopleOnlyReachByScrollingRecommendsPromotingItAndNamesIt(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit intelligence buried result', [self::FIELD => 'zqxburied']);
        $siteId = $this->fieldSectionSiteId();

        foreach ($this->record($index, 'zqxburied', results: 20, times: 4) as $eventId) {
            $this->open($eventId, (int)$entry->id, $siteId, 9);
        }

        $deep = $this->intelligence()->getDeepClicks($this->criteria($index));

        self::assertCount(1, $deep);
        self::assertSame('zqxburied', $deep[0]->query);
        self::assertSame(9.0, $deep[0]->averagePosition);
        self::assertSame($entry->title, $deep[0]->label, 'A recommendation about a result says which result.');

        $recommendation = $this->recommendationFor($index, 'zqxburied');

        self::assertSame(RecommendationType::PromoteResult, $recommendation->type);
        self::assertStringContainsString('position 9', $recommendation->reason);
    }

    public function testTheIntentBreakdownCountsSearchesByHowTheirWordingReads(): void
    {
        $index = $this->measuredIndex();

        $this->record($index, 'how to clean a kettle', results: 2, times: 3);
        $this->record($index, 'kettle delivery', results: 2, times: 2);
        $this->record($index, 'blue kettle', results: 2, times: 1);

        $breakdown = $this->intelligence()->getIntentBreakdown($this->criteria($index));

        self::assertSame(3, $breakdown[SearchIntent::Informational->value] ?? 0);
        self::assertSame(2, $breakdown[SearchIntent::Transactional->value] ?? 0);
        self::assertSame(1, $breakdown[SearchIntent::Unknown->value] ?? 0, 'A query saying nothing is counted as saying nothing.');
    }

    public function testWhatOtherPeopleSearchedForIsNotOfferedUnlessTheIndexAsksForIt(): void
    {
        $index = $this->measuredIndex();
        $this->indexPage($index, 'SearchKit intelligence zqxoffered thing');
        $this->popularSearch($index, 'zqxoffered thing');

        self::assertSame(
            [],
            $this->suggestable($index, 'zqxoffered'),
            'Showing one visitor’s wording to the next is off unless it is asked for.',
        );

        $this->allowHistory($index);

        self::assertSame(['zqxoffered thing'], $this->suggestable($index, 'zqxoffered'));
        self::assertContains('zqxoffered thing', $this->autocomplete($index, 'zqxoffered'));
    }

    public function testAPastSearchIsOnlyOfferedOnceItHasRepeatedAcrossSeparateDays(): void
    {
        $index = $this->measuredIndex();
        $this->allowHistory($index);
        $this->indexPage($index, 'SearchKit intelligence zqxrare thing');

        // Searched for often, but all in one burst on a single day.
        $this->record($index, 'zqxrare thing', results: 3, times: 8, clicked: true);

        self::assertSame(
            [],
            $this->suggestable($index, 'zqxrare'),
            'A single day’s burst of searching is not yet a repeated search.',
        );

        $this->record($index, 'zqxrare thing', results: 3, times: 1, modifier: '-2 days', clicked: true);

        self::assertSame(['zqxrare thing'], $this->suggestable($index, 'zqxrare'));
    }

    public function testAPastSearchNothingWasOpenedFromIsNeverOffered(): void
    {
        $index = $this->measuredIndex();
        $this->allowHistory($index);
        $this->indexPage($index, 'SearchKit intelligence zqxignored thing');

        $this->popularSearch($index, 'zqxignored thing', clicked: false);

        self::assertSame([], $this->suggestable($index, 'zqxignored'));
    }

    public function testAPastSearchNamingWordsNoPublishedContentUsesIsNeverOffered(): void
    {
        $index = $this->measuredIndex();
        $this->allowHistory($index);

        // Recorded exactly as a popular, successful search — but the content behind it has gone.
        $this->popularSearch($index, 'zqxvanished thing');

        self::assertSame(
            ['zqxvanished thing'],
            $this->suggestable($index, 'zqxvanished'),
            'The recorded activity on its own says nothing about what may be shown.',
        );

        self::assertSame(
            [],
            $this->autocomplete($index, 'zqxvanished'),
            'Every word of an offered query has to be a word published content still uses.',
        );
    }

    public function testAPastSearchIsOnlyOfferedWhereItsWordsAreVisible(): void
    {
        $index = $this->measuredIndex();
        $this->allowHistory($index);
        $this->indexPage($index, 'SearchKit intelligence zqxhidden thing', enabled: false);

        $this->popularSearch($index, 'zqxhidden thing');

        self::assertSame([], $this->autocomplete($index, 'zqxhidden'), 'Unpublished content offers nothing.');
    }

    public function testEngagementNeverCountsAnIndexThatFollowsNoClicksAsOneNobodyOpened(): void
    {
        $following = $this->measuredIndex();
        $ignoring = $this->measuredIndex(new AnalyticsSettings(['trackClicks' => false]));

        // Ten searches each. Half of the followed index's searches led to something being opened;
        // the other index recorded nothing about clicks at all, because it was told not to.
        $opened = $this->record($following, 'zqxfollowed', results: 3, times: 10);

        foreach (array_slice($opened, 0, 5) as $eventId) {
            $this->open($eventId, 401, $this->fieldSectionSiteId());
        }

        $this->record($ignoring, 'zqxignoring', results: 3, times: 10);

        $score = $this->intelligence()->getQualityScore($this->across($following, $ignoring));

        self::assertSame(20, $score->searches, 'Success and speed cover both indexes.');
        self::assertSame(10, $score->engagementSearches, 'Engagement covers only the watched half.');
        self::assertSame(0.5, $score->engagement);
        self::assertTrue($score->engagementIsPartial());

        // The bug this guards: 5 clicks over all 20 searches, marking the second index down for
        // engagement that was never being recorded for it.
        self::assertNotSame(0.25, $score->engagement);
    }

    public function testEngagementCoversEverythingWhenEveryIndexFollowsClicks(): void
    {
        $first = $this->measuredIndex();
        $second = $this->measuredIndex();

        foreach ([$first, $second] as $index) {
            foreach ($this->record($index, 'zqxboth', results: 3, times: 10) as $eventId) {
                $this->open($eventId, 402, $this->fieldSectionSiteId());
            }
        }

        $score = $this->intelligence()->getQualityScore($this->across($first, $second));

        self::assertSame(20, $score->searches);
        self::assertSame(20, $score->engagementSearches);
        self::assertSame(1.0, $score->engagement);
        self::assertFalse($score->engagementIsPartial());
    }

    public function testEngagementIsUnavailableWhenNoIndexInTheReadingFollowsClicks(): void
    {
        $first = $this->measuredIndex(new AnalyticsSettings(['trackClicks' => false]));
        $second = $this->measuredIndex(new AnalyticsSettings(['trackClicks' => false]));

        $this->record($first, 'zqxuntracked', results: 3, times: 5);
        $this->record($second, 'zqxuntracked', results: 3, times: 5);

        $score = $this->intelligence()->getQualityScore($this->across($first, $second));

        self::assertNull($score->engagement);
        self::assertSame(['engagement'], $score->getMissingComponents());
        self::assertSame(100.0, $score->score, 'Everything that could be measured was perfect.');
    }

    public function testEachSearchIsJudgedSlowByItsOwnIndexRatherThanByOneThresholdForAllOfThem(): void
    {
        $strict = $this->measuredIndex(new AnalyticsSettings(['slowThreshold' => 500]));
        $relaxed = $this->measuredIndex(new AnalyticsSettings(['slowThreshold' => 2000]));

        // The same 900ms either side: slow for the index that calls 500ms slow, comfortable for the
        // one that calls 2000ms slow.
        $this->record($strict, 'zqxstrict', results: 3, times: 5, time: 900.0);
        $this->record($relaxed, 'zqxrelaxed', results: 3, times: 5, time: 900.0);

        $naive = $this->across($strict, $relaxed);
        $naive->slowThreshold = 500;

        self::assertSame(
            10,
            $this->insights()->getSummary($naive)->slowSearches,
            'One threshold for every index counts the relaxed index’s searches as slow too.',
        );

        $aware = $this->intelligence()->judgedByOwnIndex($this->across($strict, $relaxed));

        self::assertSame(5, $this->insights()->getSummary($aware)->slowSearches);
        self::assertSame(
            0.5,
            $this->intelligence()->getQualityScore($this->across($strict, $relaxed))->speed,
            'The score reads the same threshold-aware count.',
        );
    }

    public function testASlowQueryHasToClearTheStrictestThresholdOfTheIndexesItSpans(): void
    {
        $relaxed = $this->measuredIndex(new AnalyticsSettings(['slowThreshold' => 2000]));

        $this->record($relaxed, 'zqxrelaxedquery', results: 3, times: 4, time: 900.0);

        $aware = $this->intelligence()->judgedByOwnIndex($this->criteria($relaxed));

        self::assertSame(
            [],
            $this->insights()->getSlowQueries($aware),
            'An index that calls 2000ms acceptable has no slow queries at 900ms.',
        );
    }

    public function testAPastSearchFromOneSiteIsNotOfferedInAnother(): void
    {
        $siteIds = $this->twoSites();
        $index = $this->measuredIndex();
        $this->allowHistory($index);
        $this->indexPage($index, 'zqxsitebound thing');

        // Searched for, and opened, only in the first site.
        $this->popularSearch($index, 'zqxsitebound thing', siteId: $siteIds[0]);

        self::assertSame(
            ['zqxsitebound thing'],
            $this->suggestable($index, 'zqxsitebound', [$siteIds[0]]),
            'The site it was searched in offers it.',
        );

        self::assertSame(
            [],
            $this->suggestable($index, 'zqxsitebound', [$siteIds[1]]),
            'Another site never searched for it, so offering it there would cross a boundary its '
            . 'words being visible does not license.',
        );

        self::assertSame(
            ['zqxsitebound thing'],
            $this->suggestable($index, 'zqxsitebound', null),
            'Every site the index covers includes the site it was searched in.',
        );
    }

    public function testASearchCoveringEverySiteIsNotOfferedWhenSuggestingForOneOfThem(): void
    {
        $siteIds = $this->twoSites();
        $index = $this->measuredIndex();
        $this->allowHistory($index);
        $this->indexPage($index, 'zqxwholescope thing');

        $this->popularSearch($index, 'zqxwholescope thing', everySite: true);

        self::assertSame(
            ['zqxwholescope thing'],
            $this->suggestable($index, 'zqxwholescope', null),
            'The whole scope is the scope it was searched under.',
        );

        foreach ($siteIds as $siteId) {
            self::assertSame(
                [],
                $this->suggestable($index, 'zqxwholescope', [$siteId]),
                'A search of every site is not a search of any one of them.',
            );
        }
    }

    public function testAutocompleteOffersAPastSearchOnlyInsideTheScopeBeingSearched(): void
    {
        $siteIds = $this->twoSites();
        $index = $this->measuredIndex();
        $this->allowHistory($index);
        $this->indexPage($index, 'zqxscoped thing');

        $this->popularSearch($index, 'zqxscoped thing', siteId: $siteIds[0]);

        self::assertContains(
            'zqxscoped thing',
            $this->autocomplete($index, 'zqxscoped', ['siteId' => $siteIds[0]]),
        );

        self::assertNotContains(
            'zqxscoped thing',
            $this->autocomplete($index, 'zqxscoped', ['siteId' => $siteIds[1]]),
            'A completion is looked for among the searches of the sites being searched.',
        );
    }

    public function testTwoQueriesSearchedUnderDifferentScopesAreNotOfferedAsASynonym(): void
    {
        $index = $this->measuredIndex();
        $siteId = $this->fieldSectionSiteId();

        // One wording searched across the index's whole scope, the other in one site — both opening
        // the same two results. Without the scope guard these would look like the same thing.
        foreach ($this->record($index, 'zqxwholeword', results: 4, times: 3, everySite: true) as $eventId) {
            $this->open($eventId, 501, $siteId);
            $this->open($eventId, 502, $siteId, 2);
        }

        foreach ($this->record($index, 'zqxsiteword', results: 4, times: 3, siteId: $siteId) as $eventId) {
            $this->open($eventId, 501, $siteId);
            $this->open($eventId, 502, $siteId, 2);
        }

        self::assertSame(
            [],
            $this->intelligence()->discoverSynonyms($this->criteria($index), $index),
            'What people did across every site is no evidence about the wording used in one of them.',
        );
    }

    public function testACandidateReportsTheScopeItWasObservedIn(): void
    {
        $index = $this->measuredIndex();
        $siteId = $this->fieldSectionSiteId();

        foreach (['zqxscopeda', 'zqxscopedb'] as $query) {
            foreach ($this->record($index, $query, results: 4, times: 3, siteId: $siteId) as $eventId) {
                $this->open($eventId, 601, $siteId);
                $this->open($eventId, 602, $siteId, 2);
            }
        }

        $candidates = $this->intelligence()->discoverSynonyms($this->criteria($index), $index);

        self::assertCount(1, $candidates);
        self::assertSame($siteId, $candidates[0]->siteId, 'A pair names the site to write the group for.');
        self::assertTrue($candidates[0]->isSiteSpecific());
    }

    public function testASynonymGroupIsCheckedAgainstTheScopeThePairWasObservedIn(): void
    {
        $index = $this->measuredIndex();
        $siteIds = $this->twoSites();

        foreach (['zqxcovereda', 'zqxcoveredb'] as $query) {
            foreach ($this->record($index, $query, results: 4, times: 3, siteId: $siteIds[0]) as $eventId) {
                $this->open($eventId, 701, $siteIds[0]);
                $this->open($eventId, 702, $siteIds[0], 2);
            }
        }

        self::assertCount(1, $this->intelligence()->discoverSynonyms($this->criteria($index), $index));

        // A group covering the pair, but written for the other site: it does not cover what was
        // observed, so the pair is still worth offering.
        $elsewhere = new Synonym([
            'indexId' => $index->id,
            'siteId' => $siteIds[1],
            'type' => SynonymType::TwoWay,
            'terms' => ['zqxcovereda', 'zqxcoveredb'],
        ]);

        self::assertTrue($this->plugin()->getSynonyms()->saveSynonym($elsewhere));

        try {
            self::assertCount(
                1,
                $this->intelligence()->discoverSynonyms($this->criteria($index), $index),
                'A group for another site does not cover a pair observed in this one.',
            );
        } finally {
            $this->plugin()->getSynonyms()->deleteSynonym($elsewhere);
        }
    }

    /**
     * @return string[]
     */
    private function suggestable(SearchIndex $index, string $prefix, ?array $siteId = null): array
    {
        // Offered queries are reused for a few minutes whatever has been recorded since, which a
        // test filling the table has to step past.
        $this->plugin()->getAnalytics()->invalidate();

        return $this->intelligence()->getSuggestableQueries($index, $prefix, 5, $siteId);
    }

    /**
     * @param array<string,mixed> $params
     * @return string[]
     */
    private function autocomplete(SearchIndex $index, string $text, array $params = []): array
    {
        return $this->plugin()->getSearch()->autocomplete(SearchQuery::create($index->handle, $text, $params));
    }

    /**
     * Two sites the section under test covers, for the scoping tests.
     *
     * @return int[]
     */
    private function twoSites(): array
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project has only one site the section under test covers.');
        }

        return $siteIds;
    }

    /**
     * A reading covering exactly these indexes and nothing else in the project.
     */
    private function across(SearchIndex ...$indexes): InsightsCriteria
    {
        $this->plugin()->getAnalytics()->invalidate();

        return new InsightsCriteria([
            'indexIds' => array_map(static fn(SearchIndex $index) => (int)$index->id, $indexes),
        ]);
    }

    /**
     * A query searched for enough times over enough days to be offered back, once an index allows it.
     */
    private function popularSearch(
        SearchIndex $index,
        string $query,
        bool $clicked = true,
        ?int $siteId = null,
        bool $everySite = false,
    ): void {
        $this->record($index, $query, results: 3, times: 5, clicked: $clicked, siteId: $siteId, everySite: $everySite);
        $this->record(
            $index,
            $query,
            results: 3,
            times: 2,
            modifier: '-2 days',
            clicked: $clicked,
            siteId: $siteId,
            everySite: $everySite,
        );
    }

    private function allowHistory(SearchIndex $index): void
    {
        $index->setAnalyticsSettings(new AnalyticsSettings(['suggestPopularQueries' => true]));
        self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));
    }

    /**
     * Content the index holds the words of, so a suggestion made of them has something behind it.
     */
    private function indexPage(SearchIndex $index, string $keywords, bool $enabled = true): Entry
    {
        $entry = $this->createPage('SearchKit intelligence content', [self::FIELD => $keywords], $enabled);
        $this->plugin()->getIndexing()->processPending($index);

        return $entry;
    }

    private function recommendationFor(SearchIndex $index, string $subject): Recommendation
    {
        foreach ($this->plugin()->getRecommendations()->forCriteria($this->criteria($index), $index) as $recommendation) {
            if ($recommendation->subject === $subject) {
                return $recommendation;
            }
        }

        self::fail("Nothing was recommended about “{$subject}”.");
    }

    private function measuredIndex(?AnalyticsSettings $settings = null): SearchIndex
    {
        // Skips rather than fails where the project carries no such field, as every other content test does.
        $this->fieldEntryType();

        $index = $this->persistIndexWithFields([Entry::class => self::FIELD]);

        if ($settings !== null) {
            $index->setAnalyticsSettings($settings);
            self::assertTrue($this->plugin()->getIndexes()->saveIndex($index));
        }

        return $index;
    }

    /**
     * Searches as they would have been recorded. Written straight to the table so a window can hold
     * enough of them to measure: what the recording itself does is covered by AnalyticsTest.
     *
     * @return int[] The recorded searches.
     */
    private function record(
        SearchIndex $index,
        string $query,
        int $results,
        int $times = 1,
        string $modifier = 'now',
        float $time = 10.0,
        bool $clicked = false,
        ?int $siteId = null,
        bool $everySite = false,
    ): array {
        $ids = [];
        // A search covering an index's whole scope records no site, exactly as the real one does.
        $recordedSite = $everySite ? null : ($siteId ?? $this->fieldSectionSiteId());

        for ($i = 0; $i < $times; $i++) {
            $token = StringHelper::UUID();

            Db::insert(Table::SEARCHEVENTS, [
                'indexId' => $index->id,
                'siteId' => $recordedSite,
                'query' => $query,
                'normalizedQuery' => $query,
                'resultCount' => $results,
                'executionTime' => $time,
                'uid' => $token,
            ]);

            $id = (int)(new Query())->select(['id'])->from([Table::SEARCHEVENTS])->where(['uid' => $token])->scalar();

            if ($modifier !== 'now') {
                Db::update(Table::SEARCHEVENTS, [
                    'dateCreated' => Db::prepareDateForDb(new DateTime($modifier)),
                ], ['id' => $id]);
            }

            if ($clicked) {
                $this->open($id, 301, $siteId ?? $this->fieldSectionSiteId());
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * A result opened from a recorded search, at the place it sat.
     */
    private function open(int $eventId, int $elementId, int $siteId, int $position = 1): void
    {
        Db::insert(Table::SEARCHCLICKS, [
            'eventId' => $eventId,
            'elementId' => $elementId,
            'elementType' => Entry::class,
            'siteId' => $siteId,
            'position' => $position,
        ]);

        Db::update(Table::SEARCHEVENTS, [
            'clickCount' => new Expression('[[clickCount]] + 1'),
        ], ['id' => $eventId]);
    }

    private function criteria(SearchIndex $index): InsightsCriteria
    {
        // Readings are cached for a few minutes, which a test filling the table has to step past.
        $this->plugin()->getAnalytics()->invalidate();

        return new InsightsCriteria(['indexId' => $index->id]);
    }

    private function intelligence(): Intelligence
    {
        return $this->plugin()->getIntelligence();
    }

    private function insights(): Insights
    {
        return $this->plugin()->getInsights();
    }
}

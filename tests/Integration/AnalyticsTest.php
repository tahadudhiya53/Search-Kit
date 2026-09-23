<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use DateTimeZone;
use Tahadudhiya\SearchKit\db\Table;
use Tahadudhiya\SearchKit\models\AnalyticsSettings;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\services\Analytics;
use Tahadudhiya\SearchKit\services\Insights;

/**
 * What real search activity is recorded as, what it adds up to, and what it never says about
 * whoever searched.
 */
class AnalyticsTest extends SearchContentTestCase
{
    public function testASearchIsRecordedWithWhatItFoundAndHowLongItTook(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertNotSame([], $result->hits, 'The search under test found nothing to record.');
        self::assertNotNull($result->trackingToken, 'A recorded search carries the token a click reports back.');

        $event = $this->event($result->trackingToken);

        self::assertSame($index->id, (int)$event['indexId']);
        self::assertSame('zebracrossing', $event['query']);
        self::assertSame('zebracrossing', $event['normalizedQuery']);
        self::assertSame($result->total, (int)$event['resultCount']);
        self::assertGreaterThan(0, (float)$event['executionTime']);
    }

    public function testASearchOfOneSiteIsRecordedInThatSitesLanguage(): void
    {
        $siteId = $this->fieldSectionSiteId();
        $index = $this->persistIndexWithFields([Entry::class => self::FIELD], null, $siteId);
        $this->createPage('SearchKit analytics language', [self::FIELD => 'zebralingua']);

        $result = $this->searchIndex($index->handle, 'zebralingua');
        $event = $this->event((string)$result->trackingToken);

        self::assertSame($siteId, (int)$event['siteId']);
        self::assertSame(Craft::$app->getSites()->getSiteById($siteId)?->language, $event['language']);
    }

    public function testASearchAcrossSitesWritingDifferentLanguagesClaimsNoLanguage(): void
    {
        $languages = [];

        foreach ($this->fieldSectionSiteIds() as $siteId) {
            $languages[] = Craft::$app->getSites()->getSiteById($siteId)?->language;
        }

        $index = $this->persistIndexWithFields([Entry::class => self::FIELD]);
        $this->createPage('SearchKit analytics scope', [self::FIELD => 'zebrascope']);

        $result = $this->searchIndex($index->handle, 'zebrascope');
        $event = $this->event((string)$result->trackingToken);

        // No site is named for a search covering several, and no language is claimed for one
        // covering sites that are written in more than one.
        self::assertNull($event['siteId']);

        if (count(array_unique($languages)) > 1) {
            self::assertNull($event['language'], 'A mixed-language search must not claim one language.');
        } else {
            self::assertSame($languages[0], $event['language']);
        }
    }

    public function testARecordedSearchNamesNobody(): void
    {
        $columns = array_keys(Craft::$app->getDb()->getTableSchema(Table::SEARCHEVENTS, true)->columns);

        foreach (['userId', 'authorId', 'ip', 'ipAddress', 'userAgent', 'sessionId', 'referrer'] as $identifying) {
            self::assertNotContains($identifying, $columns, 'Search activity must describe searches, not people.');
        }
    }

    public function testASearchThatFoundNothingIsCountedAndOffered(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $this->searchIndex($index->handle, 'zebracrossing');
        $this->searchIndex($index->handle, 'quixotibulator');

        $summary = $this->insights()->getSummary($this->criteria($index));

        self::assertSame(2, $summary->totalSearches);
        self::assertSame(2, $summary->uniqueQueries);
        self::assertSame(1, $summary->zeroResultSearches);
        self::assertSame(0.5, $summary->getZeroResultRate());

        $failed = $this->insights()->getZeroResultQueries($this->criteria($index));

        self::assertCount(1, $failed);
        self::assertSame('quixotibulator', $failed[0]->query);
        self::assertTrue($failed[0]->findsNothing());
    }

    public function testPopularQueriesCountEveryTimeAndUniqueQueriesCountEachOnce(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $this->searchIndex($index->handle, 'zebracrossing');
        $this->searchIndex($index->handle, 'Zebracrossing');
        $this->searchIndex($index->handle, 'quixotibulator');

        $popular = $this->insights()->getPopularQueries($this->criteria($index));

        self::assertSame('zebracrossing', $popular[0]->query, 'Queries group on the normalized text.');
        self::assertSame(2, $popular[0]->searches);
        self::assertSame(2, $this->insights()->getSummary($this->criteria($index))->uniqueQueries);
    }

    public function testAResultOpenedIsAssociatedWithTheSearchThatFoundIt(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertTrue($this->click($result->trackingToken, (int)$entry->id));

        $summary = $this->insights()->getSummary($this->criteria($index));

        self::assertSame(1, $summary->clicks);
        self::assertSame(1, $summary->clickedSearches);
        self::assertSame(1.0, $summary->getClickThroughRate());

        $popular = $this->insights()->getPopularQueries($this->criteria($index));

        self::assertSame(1, $popular[0]->clicks);
        self::assertSame(1.0, $popular[0]->getClickThroughRate());
    }

    public function testTheSameResultCannotBeOpenedTwiceIntoTheNumbers(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertTrue($this->click($result->trackingToken, (int)$entry->id));
        self::assertTrue($this->click($result->trackingToken, (int)$entry->id), 'A repeat is accepted.');

        self::assertSame(1, $this->insights()->getSummary($this->criteria($index))->clicks);
    }

    public function testAClickIsRefusedUnlessItNamesARealSearchAndARealResult(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertFalse($this->click('not-a-token', (int)$entry->id), 'An invented token is refused.');
        self::assertFalse($this->click($result->trackingToken, PHP_INT_MAX), 'An element that does not exist is refused.');
        self::assertFalse(
            $this->click($result->trackingToken, (int)$entry->id, PHP_INT_MAX),
            'A site that does not exist is refused.',
        );

        self::assertSame(0, $this->insights()->getSummary($this->criteria($index))->clicks);
    }

    public function testASearchOfOneSiteCannotHaveProducedAnotherSitesResult(): void
    {
        $siteIds = $this->fieldSectionSiteIds();

        if (count($siteIds) < 2) {
            self::markTestSkipped('This project has only one site the section under test covers.');
        }

        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing', ['siteId' => $siteIds[0]]);

        self::assertTrue($this->click($result->trackingToken, (int)$entry->id, $siteIds[0]));
        self::assertFalse(
            $this->click($result->trackingToken, (int)$entry->id, $siteIds[1]),
            'The same element in a site this search never returned is not this search’s click.',
        );

        self::assertSame(1, $this->insights()->getSummary($this->criteria($index))->clicks);
    }

    public function testOnlyAResultTheSearchActuallyReturnedMayBeClicked(): void
    {
        $index = $this->measuredIndex();
        $returned = $this->createPage('SearchKit analytics first', [self::FIELD => 'zebracrossing']);
        $unrelated = $this->createPage('SearchKit analytics other', [self::FIELD => 'perambulatrix']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertSame(
            [(int)$returned->id],
            array_values(array_unique($this->idsOf($result))),
            'The search under test returned one element, in every site the index covers.',
        );

        self::assertFalse(
            $this->click($result->trackingToken, (int)$unrelated->id),
            'An element this search never returned cannot report a click on it.',
        );
        self::assertTrue($this->click($result->trackingToken, (int)$returned->id));

        self::assertSame(1, $this->insights()->getSummary($this->criteria($index))->clicks);
    }

    public function testAResultOutsideTheWindowTheSearchReturnedIsNotItsResult(): void
    {
        $index = $this->measuredIndex();
        $first = $this->createPage('SearchKit analytics first', [self::FIELD => 'zebracrossing']);
        $second = $this->createPage('SearchKit analytics second', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing', ['limit' => 1]);

        self::assertCount(1, $result->hits, 'One result was returned of the several that matched.');
        self::assertGreaterThan(1, $result->total);

        $shown = (int)$result->hits[0]->elementId;
        $site = (int)$result->hits[0]->siteId;
        $notShown = $shown === (int)$first->id ? (int)$second->id : (int)$first->id;

        self::assertFalse(
            $this->click($result->trackingToken, $notShown, $site),
            'A result nobody was shown was not clicked.',
        );
        self::assertTrue($this->click($result->trackingToken, $shown, $site));
    }

    public function testASearchRemembersNoMoreResultsThanItIsAllowedTo(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');
        $tracked = Json::decodeIfJson((string)$this->event($result->trackingToken)['trackedResults']);

        self::assertIsArray($tracked);
        self::assertCount(count($result->hits), $tracked, 'A search remembers exactly what it returned.');
        self::assertLessThanOrEqual(Analytics::MAX_TRACKED_RESULTS, count($tracked));
    }

    public function testAClickIsRefusedOnceTheSearchIsNoLongerRecent(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');
        $this->backdateSearch($result->trackingToken, '-2 days');

        self::assertFalse($this->click($result->trackingToken, (int)$entry->id), 'An expired token is refused.');
        self::assertSame(0, $this->insights()->getSummary($this->criteria($index))->clicks);
    }

    public function testTheTextIsRecordedAsItWasTypedAndSeparatelyAsItReduces(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, '  ZebraCrossing   Tést  ');
        $event = $this->event($result->trackingToken);

        self::assertSame('  ZebraCrossing   Tést  ', $event['query'], 'What was typed is kept as it was typed.');
        self::assertSame('zebracrossing test', $event['normalizedQuery']);
    }

    public function testAQueryLongerThanTheColumnHoldsIsShortenedRatherThanRefused(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $long = 'zebracrossing ' . str_repeat('a', Analytics::MAX_QUERY_LENGTH * 2);
        $result = $this->searchIndex($index->handle, $long);
        $event = $this->event($result->trackingToken);

        self::assertNotNull($event, 'A search too long to store whole is still recorded.');
        self::assertSame(Analytics::MAX_QUERY_LENGTH, mb_strlen((string)$event['query']));
    }

    public function testAskingForADayCountsTheWholeOfIt(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $timezone = new DateTimeZone(Craft::$app->getTimeZone());
        $day = (new DateTime('2026-09-18 12:00:00', $timezone))->format('Y-m-d');

        $lastInstant = $this->searchIndex($index->handle, 'zebracrossing');
        $this->moveSearch($lastInstant->trackingToken, new DateTime('2026-09-18 23:59:59', $timezone));

        $nextDay = $this->searchIndex($index->handle, 'quixotibulator');
        $this->moveSearch($nextDay->trackingToken, new DateTime('2026-09-19 00:00:00', $timezone));

        $dayBefore = $this->searchIndex($index->handle, 'perambulatrix');
        $this->moveSearch($dayBefore->trackingToken, new DateTime('2026-09-17 23:59:59', $timezone));

        $criteria = InsightsCriteria::fromRequest(['indexId' => $index->id, 'dateFrom' => $day, 'dateTo' => $day]);
        $this->plugin()->getAnalytics()->invalidate();

        self::assertSame(1, $this->insights()->getSummary($criteria)->totalSearches);
        self::assertSame(
            ['zebracrossing'],
            array_map(static fn($q) => $q->query, $this->insights()->getPopularQueries($criteria)),
            'The last instant of the day asked for is counted; neither neighbouring day is.',
        );
    }

    public function testAnIndexThatRecordsNothingRecordsNothing(): void
    {
        $index = $this->measuredIndex(new AnalyticsSettings(['enabled' => false]));
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertNull($result->trackingToken);
        self::assertSame(0, $this->insights()->getSummary($this->criteria($index))->totalSearches);
    }

    public function testAnIndexThatDoesNotFollowClicksHandsOutNoToken(): void
    {
        $index = $this->measuredIndex(new AnalyticsSettings(['trackClicks' => false]));
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        self::assertNull($result->trackingToken, 'Without a token there is nothing to associate a click with.');
        self::assertSame(1, $this->insights()->getSummary($this->criteria($index))->totalSearches);
    }

    public function testUnopenedQueriesNameQueriesNothingCameOf(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        // Searched for twice and opened, so this is demand that was answered.
        $answered = $this->searchIndex($index->handle, 'zebracrossing');
        $this->click($answered->trackingToken, (int)$entry->id);
        $this->searchIndex($index->handle, 'zebracrossing');

        // Searched for twice and nothing came of it either time.
        $this->searchIndex($index->handle, 'quixotibulator');
        $this->searchIndex($index->handle, 'quixotibulator');

        // Searched for once, which is a one-off rather than a gap.
        $this->searchIndex($index->handle, 'perambulatrix');

        $gaps = $this->insights()->getUnopenedQueries($this->criteria($index));

        self::assertSame(['quixotibulator'], array_map(static fn($gap) => $gap->query, $gaps));
        self::assertSame(2, $gaps[0]->searches);
    }

    public function testSlowQueriesAreTheOnesPastWhatTheIndexCallsSlow(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');
        Db::update(Table::SEARCHEVENTS, ['executionTime' => 900], ['uid' => $result->trackingToken]);

        $criteria = $this->criteria($index);
        $criteria->slowThreshold = 500;

        self::assertSame(1, $this->insights()->getSummary($criteria)->slowSearches);
        self::assertSame('zebracrossing', $this->insights()->getSlowQueries($criteria)[0]->query);

        $criteria = $this->criteria($index);
        $criteria->slowThreshold = 5000;

        self::assertSame(0, $this->insights()->getSummary($criteria)->slowSearches);
        self::assertSame([], $this->insights()->getSlowQueries($criteria));
    }

    public function testOnlySearchesInsideTheDatesAskedForAreCounted(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $old = $this->searchIndex($index->handle, 'zebracrossing');
        $this->backdateSearch($old->trackingToken, '-10 days');
        $this->searchIndex($index->handle, 'zebracrossing');

        $criteria = $this->criteria($index);
        $criteria->dateFrom = new DateTime('-2 days');

        self::assertSame(1, $this->insights()->getSummary($criteria)->totalSearches);
        self::assertSame(2, $this->insights()->getSummary($this->criteria($index))->totalSearches);

        $trend = $this->insights()->getTrend($this->criteria($index));

        self::assertCount(2, $trend, 'Two days were searched on.');
        self::assertSame(1, $trend[0]->searches);
    }

    public function testRetentionForgetsSearchesOlderThanTheIndexKeepsThem(): void
    {
        $index = $this->measuredIndex(new AnalyticsSettings(['retentionDays' => 7]));
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $old = $this->searchIndex($index->handle, 'zebracrossing');
        $this->backdateSearch($old->trackingToken, '-30 days');
        $this->searchIndex($index->handle, 'zebracrossing');

        $this->plugin()->getAnalytics()->prune();

        self::assertSame(1, $this->insights()->getSummary($this->criteria($index))->totalSearches);
        self::assertNull($this->event($old->trackingToken), 'A search past its retention is gone.');
    }

    public function testClearingForgetsEverythingRecordedForAnIndexIncludingItsClicks(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $result = $this->searchIndex($index->handle, 'zebracrossing');
        $this->click($result->trackingToken, (int)$entry->id);

        self::assertSame(1, $this->plugin()->getAnalytics()->clear($index->id));

        self::assertSame(0, $this->insights()->getSummary($this->criteria($index))->totalSearches);
        self::assertFalse(
            (new Query())->from([Table::SEARCHCLICKS])->where(['elementId' => $entry->id])->exists(),
            'Clicks go with the searches they belonged to.',
        );
    }

    public function testAReadingIsNeverBehindWhatHasJustBeenRecorded(): void
    {
        $index = $this->measuredIndex();
        $entry = $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        // The same criteria throughout, so every reading below asks for exactly the same thing.
        $criteria = $this->criteria($index);

        $this->searchIndex($index->handle, 'zebracrossing');

        self::assertSame(1, $this->insights()->getSummary($criteria)->totalSearches);
        self::assertSame(1, count($this->insights()->getPopularQueries($criteria)));

        $result = $this->searchIndex($index->handle, 'zebracrossing');

        // Nothing was cleared in between: a reading may be reused, but never be out of date.
        self::assertSame(2, $this->insights()->getSummary($criteria)->totalSearches);
        self::assertSame(2, $this->insights()->getPopularQueries($criteria)[0]->searches);
        self::assertSame(0, $this->insights()->getSummary($criteria)->clicks);

        self::assertTrue($this->click($result->trackingToken, (int)$entry->id));

        self::assertSame(1, $this->insights()->getSummary($criteria)->clicks, 'A result opened since counts too.');
        self::assertSame(1, count($this->insights()->getClickedResults($criteria)));
    }

    public function testTheMostOpenedResultsAreCountedAndNamed(): void
    {
        $index = $this->measuredIndex();
        $popular = $this->createPage('SearchKit analytics popular result', [self::FIELD => 'zebracrossing']);
        $other = $this->createPage('SearchKit analytics other result', [self::FIELD => 'zebracrossing']);

        // A click is one per result per search, so opening the same result twice takes two searches.
        foreach ([$popular, $popular, $other] as $opened) {
            $search = $this->searchIndex($index->handle, 'zebracrossing');
            self::assertTrue($this->click($search->trackingToken, (int)$opened->id));
        }

        $results = $this->insights()->getClickedResults($this->criteria($index));

        self::assertCount(2, $results);
        self::assertSame((int)$popular->id, $results[0]->elementId);
        self::assertSame(2, $results[0]->clicks);
        self::assertSame($popular->title, $results[0]->label);
        self::assertSame($this->fieldSectionSiteId(), $results[0]->siteId);
        self::assertSame(1, $results[1]->clicks);

        $before = $this->criteria($index);
        $before->dateTo = new DateTime('-1 day');

        self::assertSame([], $this->insights()->getClickedResults($before), 'Clicks are read over the dates asked for.');
    }

    public function testTheTrendCountsEachDayAndHowLongItsSearchesTook(): void
    {
        $index = $this->measuredIndex();
        $this->createPage('SearchKit analytics subject', [self::FIELD => 'zebracrossing']);

        $old = $this->searchIndex($index->handle, 'zebracrossing');
        $this->backdateSearch($old->trackingToken, '-2 days');
        $this->searchIndex($index->handle, 'zebracrossing');
        $this->searchIndex($index->handle, 'quixotibulator');

        $trend = $this->insights()->getTrend($this->criteria($index));

        self::assertCount(2, $trend);
        self::assertSame(1, $trend[0]->searches);
        self::assertSame($trend[0]->date, $trend[0]->dateEnd, 'A day-by-day point stands for the one day.');
        self::assertSame(2, $trend[1]->searches);
        self::assertSame(1, $trend[1]->zeroResults);
        self::assertGreaterThan(0, $trend[1]->averageTime);
    }

    /**
     * An index that records its searches, registered for removal with the activity it recorded.
     */
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

    private function criteria(SearchIndex $index): InsightsCriteria
    {
        // Readings are cached for a few minutes, which a test filling the table has to step past.
        $this->plugin()->getAnalytics()->invalidate();

        return new InsightsCriteria(['indexId' => $index->id]);
    }

    private function insights(): Insights
    {
        return $this->plugin()->getInsights();
    }

    private function click(?string $token, int $elementId, ?int $siteId = null): bool
    {
        return $this->plugin()->getAnalytics()->recordClick(
            (string)$token,
            $elementId,
            $siteId ?? $this->fieldSectionSiteId(),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function event(?string $token): ?array
    {
        $row = (new Query())
            ->from([Table::SEARCHEVENTS])
            ->where(['uid' => (string)$token])
            ->one();

        return $row === false ? null : $row;
    }

    private function backdateSearch(?string $token, string $modifier): void
    {
        $this->moveSearch($token, new DateTime($modifier));
    }

    private function moveSearch(?string $token, DateTime $date): void
    {
        Db::update(Table::SEARCHEVENTS, [
            'dateCreated' => Db::prepareDateForDb($date),
        ], ['uid' => (string)$token]);
    }
}

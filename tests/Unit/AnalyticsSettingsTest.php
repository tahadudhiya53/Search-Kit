<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\AnalyticsSettings;
use Tahadudhiya\SearchKit\models\InsightsCriteria;
use Tahadudhiya\SearchKit\models\InsightsSummary;
use Tahadudhiya\SearchKit\models\QueryInsight;

/**
 * What an index records, and the arithmetic every reading of it is reported with.
 */
class AnalyticsSettingsTest extends TestCase
{
    public function testDefaultsRecordSearchesAndKeepThemForALimitedTime(): void
    {
        $settings = new AnalyticsSettings();

        self::assertTrue($settings->enabled);
        self::assertTrue($settings->trackClicks);
        self::assertGreaterThan(0, $settings->retentionDays);
        self::assertLessThanOrEqual(AnalyticsSettings::MAX_RETENTION_DAYS, $settings->retentionDays);
    }

    public function testPostedSwitchesAndNumbersAreRead(): void
    {
        $settings = AnalyticsSettings::fromInput([
            'enabled' => '1',
            'trackClicks' => '',
            'retentionDays' => '30',
            'slowThreshold' => '250',
        ]);

        self::assertTrue($settings->validate());
        self::assertTrue($settings->enabled);
        self::assertFalse($settings->trackClicks);
        self::assertSame(30, $settings->retentionDays);
        self::assertSame(250, $settings->slowThreshold);
    }

    public function testAPostedMistakeIsReportedRatherThanGuessedAt(): void
    {
        $settings = AnalyticsSettings::fromInput(['enabled' => 'maybe', 'retentionDays' => '12.7']);

        self::assertFalse($settings->validate());
        self::assertTrue($settings->enabled, 'An unreadable value leaves the default in place.');
    }

    public function testAnUnknownSettingIsRefusedWhenPostedButIgnoredWhenStored(): void
    {
        self::assertFalse(AnalyticsSettings::fromInput(['keepForever' => true])->validate());
        self::assertTrue(AnalyticsSettings::fromConfig(['keepForever' => true])->validate());
    }

    public function testRetentionCannotBeSetBeyondWhatIsAllowed(): void
    {
        self::assertFalse(AnalyticsSettings::fromInput(['retentionDays' => '0'])->validate());
        self::assertFalse(AnalyticsSettings::fromInput([
            'retentionDays' => (string)(AnalyticsSettings::MAX_RETENTION_DAYS + 1),
        ])->validate());
    }

    public function testStoredSettingsSurviveARoundTrip(): void
    {
        $settings = AnalyticsSettings::fromConfig((new AnalyticsSettings([
            'enabled' => false,
            'retentionDays' => 14,
        ]))->toConfig());

        self::assertFalse($settings->enabled);
        self::assertSame(14, $settings->retentionDays);
    }

    public function testRatesAreCountedOnSearchesRatherThanClicks(): void
    {
        $summary = new InsightsSummary([
            'totalSearches' => 10,
            'zeroResultSearches' => 2,
            'clicks' => 12,
            'clickedSearches' => 5,
        ]);

        self::assertSame(0.2, $summary->getZeroResultRate());
        self::assertSame(0.5, $summary->getClickThroughRate(), 'Five searches of ten led somewhere.');
    }

    public function testRatesOfNothingAreZeroRatherThanUndefined(): void
    {
        self::assertSame(0.0, (new InsightsSummary())->getClickThroughRate());
        self::assertSame(0.0, (new QueryInsight())->getZeroResultRate());
    }

    public function testAQueryThatSometimesFindsSomethingIsNotAGap(): void
    {
        $always = new QueryInsight(['searches' => 4, 'zeroResults' => 4]);
        $sometimes = new QueryInsight(['searches' => 4, 'zeroResults' => 3]);

        self::assertTrue($always->findsNothing());
        self::assertFalse($sometimes->findsNothing());
        self::assertSame(0.75, $sometimes->getZeroResultRate());
    }

    public function testTwoDifferentFiltersCannotShareAReading(): void
    {
        $one = new InsightsCriteria(['indexId' => 1]);
        $another = new InsightsCriteria(['indexId' => 2]);

        self::assertNotSame($one->cacheKey('popular'), $another->cacheKey('popular'));
        self::assertNotSame($one->cacheKey('popular'), $one->cacheKey('gaps'));
        self::assertSame($one->cacheKey('popular'), (new InsightsCriteria(['indexId' => 1]))->cacheKey('popular'));
    }

    public function testAFilterReadsTheNumbersARequestCarriesAsStrings(): void
    {
        $criteria = InsightsCriteria::fromRequest(['indexId' => '3', 'siteId' => '', 'limit' => '10']);

        self::assertSame(3, $criteria->indexId);
        self::assertNull($criteria->siteId);
        self::assertSame(10, $criteria->limit);
    }

    public function testAFilterCannotAskForMoreRowsThanAreAllowed(): void
    {
        $criteria = InsightsCriteria::fromRequest(['limit' => (string)(InsightsCriteria::MAX_LIMIT + 500)]);

        self::assertSame(InsightsCriteria::MAX_LIMIT, $criteria->limit);
    }
}

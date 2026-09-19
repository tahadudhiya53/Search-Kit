<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\TrendPoint;
use Tahadudhiya\SearchKit\services\Insights;

/**
 * Reading a long period a few points at a time, without losing any of it.
 */
class TrendTest extends TestCase
{
    public function testAShortPeriodIsLeftADayAtATime(): void
    {
        $points = $this->days(10);
        $condensed = (new Insights())->condenseTrend($points, 60);

        self::assertCount(10, $condensed);
        self::assertFalse($condensed[0]->coversRange(), 'A point covering one day is still one day.');
    }

    public function testALongPeriodIsGroupedWithoutLosingAnythingFromIt(): void
    {
        $points = $this->days(100);
        $condensed = (new Insights())->condenseTrend($points, 20);

        self::assertLessThanOrEqual(20, count($condensed));
        self::assertSame('2026-01-01', $condensed[0]->date);
        self::assertSame('2026-04-10', $condensed[count($condensed) - 1]->dateEnd);
        self::assertTrue($condensed[0]->coversRange(), 'A grouped point says which days it stands for.');

        self::assertSame(
            array_sum(array_map(static fn(TrendPoint $point) => $point->searches, $points)),
            array_sum(array_map(static fn(TrendPoint $point) => $point->searches, $condensed)),
            'Grouping days must not lose any of the searches in them.',
        );
    }

    public function testATimeIsWeightedByHowMuchWasSearchedRatherThanByTheDay(): void
    {
        $condensed = (new Insights())->condenseTrend([
            new TrendPoint(['date' => '2026-01-01', 'dateEnd' => '2026-01-01', 'searches' => 9, 'averageTime' => 100.0]),
            new TrendPoint(['date' => '2026-01-02', 'dateEnd' => '2026-01-02', 'searches' => 1, 'averageTime' => 1000.0]),
        ], 1);

        self::assertCount(1, $condensed);
        self::assertSame(190.0, $condensed[0]->averageTime, 'A quiet day cannot outweigh a busy one.');
    }

    /**
     * @return TrendPoint[]
     */
    private function days(int $count): array
    {
        $points = [];

        for ($day = 0; $day < $count; $day++) {
            $date = date('Y-m-d', strtotime("2026-01-01 +{$day} days"));
            $points[] = new TrendPoint([
                'date' => $date,
                'dateEnd' => $date,
                'searches' => $day + 1,
                'zeroResults' => 1,
                'averageTime' => 50.0,
            ]);
        }

        return $points;
    }
}

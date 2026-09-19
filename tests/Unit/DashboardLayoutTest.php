<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\helpers\Json;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\models\DashboardLayout;

/**
 * How somebody arranged their dashboard, and what an arrangement does with anything it does not
 * recognise.
 */
class DashboardLayoutTest extends TestCase
{
    public function testAnArrangementStartsAsEveryPanelAtItsOwnWidth(): void
    {
        $layout = DashboardLayout::fromConfig(null);

        self::assertSame(DashboardLayout::PANELS, $layout->getVisiblePanels());
        self::assertSame([], $layout->getHiddenPanels());
        self::assertTrue($layout->isDefault());
    }

    public function testAPanelTheArrangementNeverHeardOfIsShownAtItsDefaultWidth(): void
    {
        // What somebody stored before the dashboard grew the rest of its panels.
        $layout = DashboardLayout::fromConfig(Json::encode(['panels' => ['trend' => 4]]));

        self::assertSame(4, $layout->getSpan('trend'));
        self::assertSame(DashboardLayout::PANELS['clicked'], $layout->getSpan('clicked'));
        self::assertEqualsCanonicalizing(array_keys(DashboardLayout::PANELS), array_keys($layout->getVisiblePanels()));
        self::assertSame('trend', array_key_first($layout->getVisiblePanels()), 'What was stored keeps its place, and the rest follow.');
    }

    public function testWhatCannotBeReadIsIgnoredRatherThanShownAsItself(): void
    {
        $layout = DashboardLayout::fromConfig(Json::encode([
            'panels' => ['trend' => 99, 'popular' => 0, 'somethingElse' => 2],
            'hidden' => ['clicked', 'nothingLikeIt'],
        ]));

        self::assertSame(DashboardLayout::COLUMNS, $layout->getSpan('trend'), 'A panel is never wider than the dashboard.');
        self::assertSame(1, $layout->getSpan('popular'));
        self::assertArrayNotHasKey('somethingElse', $layout->getVisiblePanels());
        self::assertSame(['clicked'], $layout->getHiddenPanels());
    }

    public function testNothingIsLostByAnOrderThatNamesOnlySomeOfThePanels(): void
    {
        $layout = DashboardLayout::fromConfig(null);
        $layout->reorder(['health', 'clicked']);

        $panels = array_keys($layout->getVisiblePanels());

        self::assertSame(['health', 'clicked'], array_slice($panels, 0, 2));
        self::assertSame(count(DashboardLayout::PANELS), count($panels), 'Panels nobody named keep their place after them.');
        self::assertSame(DashboardLayout::PANELS['health'], $layout->getSpan('health'), 'Reordering does not resize anything.');
    }

    public function testAnArrangementComesBackOutTheWayItWasPutIn(): void
    {
        $layout = DashboardLayout::fromConfig(null);
        $layout->reorder(['clicked', 'health']);
        $layout->setSpan('clicked', 4);
        $layout->setHidden('outcomes', true);

        $stored = DashboardLayout::fromConfig(Json::encode($layout->toConfig()));

        self::assertSame(array_keys($layout->getVisiblePanels()), array_keys($stored->getVisiblePanels()));
        self::assertSame(4, $stored->getSpan('clicked'));
        self::assertSame(['outcomes'], $stored->getHiddenPanels());
        self::assertFalse($stored->isDefault());

        $stored->setHidden('outcomes', false);

        self::assertSame([], $stored->getHiddenPanels());
    }
}

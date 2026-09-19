<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\User;
use Tahadudhiya\SearchKit\models\DashboardLayout;
use Tahadudhiya\SearchKit\records\DashboardLayoutRecord;
use Tahadudhiya\SearchKit\services\DashboardLayouts;

/**
 * That an arrangement really survives the request that made it, and belongs to one person.
 */
class DashboardLayoutTest extends IntegrationTestCase
{
    private ?int $userId = null;
    private ?string $existing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::find()->admin(true)->status(null)->one();

        if ($user === null) {
            self::markTestSkipped('This project has no account to arrange a dashboard for.');
        }

        $this->userId = (int)$user->id;

        // Whatever this account had arranged is put aside, so the test starts from the default.
        $this->existing = DashboardLayoutRecord::findOne(['userId' => $this->userId])?->layout;
        DashboardLayoutRecord::deleteAll(['userId' => $this->userId]);
    }

    protected function tearDown(): void
    {
        // Puts back whatever this account had arranged before the test ran.
        if ($this->userId !== null) {
            DashboardLayoutRecord::deleteAll(['userId' => $this->userId]);

            if ($this->existing !== null) {
                $record = new DashboardLayoutRecord(['userId' => $this->userId, 'layout' => $this->existing]);
                $record->save();
            }
        }

        parent::tearDown();
    }

    public function testAnArrangementIsKeptForTheOnePersonAndCanBePutBack(): void
    {
        $layouts = $this->plugin()->getDashboardLayouts();
        $layout = $layouts->getForUser($this->userId);

        self::assertTrue($layout->isDefault(), 'An account that has arranged nothing sees the default.');

        $layout->reorder(['health', 'clicked']);
        $layout->setSpan('clicked', 4);
        $layout->setHidden('outcomes', true);

        self::assertTrue($layouts->save($this->userId, $layout));

        // Read through a service that has memoized nothing, so this really came back out of the database.
        $stored = (new DashboardLayouts())->getForUser($this->userId);

        self::assertSame(['health', 'clicked'], array_slice(array_keys($stored->getVisiblePanels()), 0, 2));
        self::assertSame(4, $stored->getSpan('clicked'));
        self::assertSame(['outcomes'], $stored->getHiddenPanels());

        // Saving again replaces the one arrangement rather than adding another.
        self::assertTrue($layouts->save($this->userId, $stored));
        self::assertSame(1, DashboardLayoutRecord::find()->where(['userId' => $this->userId])->count());

        self::assertTrue($layouts->reset($this->userId));
        self::assertTrue((new DashboardLayouts())->getForUser($this->userId)->isDefault());
    }

    public function testAnArrangementBelongsToNobodyElse(): void
    {
        $layout = DashboardLayout::fromConfig(null);
        $layout->setHidden('trend', true);
        $this->plugin()->getDashboardLayouts()->save($this->userId, $layout);

        // Nobody else's dashboard moved: every other account still reads the default.
        $other = (new DashboardLayouts())->getForUser($this->userId + 100000);

        self::assertTrue($other->isDefault());
        self::assertSame(0, DashboardLayoutRecord::find()->where(['userId' => $this->userId + 100000])->count());
    }
}

<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use craft\elements\Category;
use craft\elements\Entry;
use DateTime;
use Tahadudhiya\SearchKit\enums\FilterOperator;
use Tahadudhiya\SearchKit\errors\InvalidQueryException;
use Tahadudhiya\SearchKit\events\RegisterFilterCriteriaEvent;
use Tahadudhiya\SearchKit\models\SearchableField;
use Tahadudhiya\SearchKit\models\SearchFilter;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\providers\CraftProvider;
use Tahadudhiya\SearchKit\services\Commerce;
use yii\base\Event;

/**
 * Commerce is optional, so what has to hold here is that SearchKit is unchanged without it and that
 * the seams it hangs off work on ordinary Craft content. Commerce's own runtime behaviour is only
 * covered where Commerce is installed, which it is not in this project.
 */
class CommerceIntegrationTest extends SearchContentTestCase
{
    private const TERM = 'zqxwcommerce';

    /** @var callable|null The criteria handler standing in for what Commerce registers. */
    private $criteriaHandler = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['alpha', 'bravo', 'charlie'] as $value) {
            $this->createPage('Zqxwcommerce ' . $value);
        }
    }

    protected function tearDown(): void
    {
        if ($this->criteriaHandler !== null) {
            Event::off(CraftProvider::class, CraftProvider::EVENT_REGISTER_FILTER_CRITERIA, $this->criteriaHandler);
            $this->criteriaHandler = null;
        }

        parent::tearDown();
    }

    public function testSearchKitRunsWithoutCommerce(): void
    {
        $commerce = $this->plugin()->getCommerce();

        if ($commerce->isInstalled()) {
            self::markTestSkipped('Commerce is installed, so its absence cannot be tested here.');
        }

        // Commerce's classes may still be on disk; what decides this is whether the plugin is
        // there, so an installation that merely has the package is still an installation without it.
        self::assertSame(0, $commerce->getMajorVersion());
        self::assertSame([], $commerce->getCriteria(Commerce::PRODUCT));
        self::assertSame([], $commerce->getCriteria(Commerce::VARIANT));

        $indexable = $this->plugin()->getSearchableFields()->getIndexableElementTypes();
        self::assertNotContains(Commerce::PRODUCT, $indexable);
        self::assertNotContains(Commerce::VARIANT, $indexable);

        // Registering has to stay inert, and ordinary indexing and searching unaffected by it.
        $commerce->register();

        self::assertSame($indexable, $this->plugin()->getSearchableFields()->getIndexableElementTypes());
        self::assertSame(3, $this->search()->total);
        self::assertSame(1, $this->search(SearchFilter::make('title', FilterOperator::Equals, 'Zqxwcommerce bravo'))->total);
    }

    public function testCommerceOffersNoCriteriaItCannotResolve(): void
    {
        $commerce = $this->plugin()->getCommerce();

        // The integration contributes to Commerce element types and to nothing else.
        self::assertSame([], $commerce->getCriteria(Entry::class));

        if ($commerce->isInstalled()) {
            self::assertNotSame([], $commerce->getCriteria(Commerce::PRODUCT));
            self::assertNotSame([], $commerce->getCriteria(Commerce::VARIANT));

            return;
        }

        // With no Commerce there is no query to resolve them against, so nothing is offered.
        self::assertSame([], $commerce->getCriteria(Commerce::PRODUCT));
        self::assertSame([], $commerce->getCriteria(Commerce::VARIANT));
    }

    public function testRegisteredCriteriaReachTheElementQuery(): void
    {
        $recent = SearchFilter::make('postDate', FilterOperator::GreaterThan, new DateTime('-1 day'));

        // Nothing registers `postDate`, so it is refused rather than quietly dropped.
        $this->expectRefusal($recent);

        // Registered against another element type, it still does not apply to this one.
        $this->registerCriteria(Category::class, ['postDate']);
        $this->expectRefusal($recent);

        $this->registerCriteria(Entry::class, ['postDate']);

        self::assertSame(3, $this->search($recent)->total);
        self::assertSame(
            0,
            $this->search(SearchFilter::make('postDate', FilterOperator::LessThan, new DateTime('-1 day')))->total,
            'A registered criterion has to reach the element query, not merely be accepted.',
        );
    }

    public function testRelatedToNarrowsByRelationship(): void
    {
        $unrelated = $this->search()->getElementIds()[0];

        self::assertSame(3, $this->search()->total);
        self::assertSame(
            0,
            $this->search(SearchFilter::make(CraftProvider::FILTER_RELATED_TO, FilterOperator::Equals, $unrelated))->total,
        );
    }

    public function testRelatedToOnlyEverTakesElementIds(): void
    {
        $this->expectRefusal(
            SearchFilter::make(CraftProvider::FILTER_RELATED_TO, FilterOperator::Equals, 'someHandle'),
        );

        $this->expectRefusal(
            SearchFilter::make(CraftProvider::FILTER_RELATED_TO, FilterOperator::NotEquals, 1),
        );

        $this->expectRefusal(
            SearchFilter::make(CraftProvider::FILTER_RELATED_TO, FilterOperator::NotIn, [1, 2]),
        );
    }

    /**
     * @param string[] $criteria
     */
    private function registerCriteria(string $elementType, array $criteria): void
    {
        $this->criteriaHandler = static function(RegisterFilterCriteriaEvent $event) use ($elementType, $criteria) {
            if ($event->elementType === $elementType) {
                $event->criteria = array_merge($event->criteria, $criteria);
            }
        };

        Event::on(CraftProvider::class, CraftProvider::EVENT_REGISTER_FILTER_CRITERIA, $this->criteriaHandler);
    }

    private function expectRefusal(SearchFilter $filter): void
    {
        try {
            $this->search($filter);
            self::fail("“{$filter->field}” should have been refused rather than applied.");
        } catch (InvalidQueryException $e) {
            self::assertArrayHasKey('filters', $e->getErrors());
        }
    }

    /**
     * A fresh provider each time, so nothing a previous search resolved is reused.
     */
    private function search(?SearchFilter $filter = null): SearchResult
    {
        $query = SearchQuery::create('skCommerce', self::TERM);

        if ($filter !== null) {
            $query->addFilter($filter);
        }

        return (new CraftProvider())->search($query, $this->index());
    }

    private function index(): SearchIndex
    {
        $index = $this->newIndex();
        $index->handle = 'skCommerce';
        $index->siteId = $this->fieldSectionSiteId();
        $index->setFields([new SearchableField(['elementType' => Entry::class, 'handle' => 'title'])]);

        return $index;
    }
}

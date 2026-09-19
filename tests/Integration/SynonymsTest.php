<?php

namespace Tahadudhiya\SearchKit\Tests\Integration;

use Craft;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\Synonym;
use Tahadudhiya\SearchKit\services\Synonyms;

/**
 * Round-trips synonyms through the real database, including the cache every search reads them from.
 */
class SynonymsTest extends IntegrationTestCase
{
    private SearchIndex $index;

    /** @var Synonym[] */
    private array $createdSynonyms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->index = $this->persistIndex($this->newIndex());
    }

    protected function tearDown(): void
    {
        foreach ($this->createdSynonyms as $synonym) {
            $this->service()->deleteSynonym($synonym);
        }

        $this->createdSynonyms = [];

        parent::tearDown();
    }

    public function testRoundTripsThroughTheDatabase(): void
    {
        $synonym = $this->save(['boots', 'footwear']);
        $read = $this->freshSynonyms()->getSynonymById((int)$synonym->id);

        self::assertNotNull($read);
        self::assertSame(SynonymType::TwoWay, $read->type);
        self::assertSame(['boots', 'footwear'], $read->terms);
        self::assertSame([], $read->replacements);
        self::assertTrue($read->enabled);
        self::assertSame($synonym->uid, $read->uid);
    }

    public function testWordsAreNormalizedTheWayIndexedContentIs(): void
    {
        $synonym = $this->save(['Café', 'COFFEE shop!']);

        self::assertSame(['cafe', 'coffee shop'], $synonym->terms);
        self::assertSame(['cafe', 'coffee shop'], $this->freshSynonyms()->getSynonymById((int)$synonym->id)?->terms);
    }

    public function testAOneWayGroupKeepsItsReplacements(): void
    {
        $synonym = $this->save(['tv'], ['television'], SynonymType::OneWay);
        $read = $this->freshSynonyms()->getSynonymById((int)$synonym->id);

        self::assertSame(['television'], $read?->replacements);
        self::assertSame(['television'], $this->service()->expand('tv', $this->index));
        self::assertSame([], $this->service()->expand('television', $this->index));
    }

    public function testASavedGroupIsVisibleToTheNextSearchRatherThanACachedOne(): void
    {
        $synonym = $this->save(['boots', 'footwear']);

        self::assertSame(['footwear'], $this->freshSynonyms()->expand('boots', $this->index));

        $synonym->terms = ['boots', 'wellies'];
        self::assertTrue($this->service()->saveSynonym($synonym));

        self::assertSame(['wellies'], $this->freshSynonyms()->expand('boots', $this->index));

        $this->service()->deleteSynonym($synonym);
        $this->createdSynonyms = [];

        self::assertSame([], $this->freshSynonyms()->expand('boots', $this->index));
    }

    public function testAGroupScopedToAnotherIndexIsNotApplied(): void
    {
        $other = $this->persistIndex($this->newIndex());
        $this->save(['boots', 'footwear'], indexId: (int)$other->id);

        self::assertSame([], $this->freshSynonyms()->expand('boots', $this->index));
        self::assertSame(['footwear'], $this->freshSynonyms()->expand('boots', $other));
    }

    public function testADisabledGroupIsNotApplied(): void
    {
        $synonym = $this->save(['boots', 'footwear']);
        $synonym->enabled = false;
        self::assertTrue($this->service()->saveSynonym($synonym));

        self::assertSame([], $this->freshSynonyms()->expand('boots', $this->index));
    }

    public function testDeletingAnIndexTakesItsOwnGroupsWithIt(): void
    {
        $index = $this->persistIndex($this->newIndex());
        $synonym = $this->save(['boots', 'footwear'], indexId: (int)$index->id);
        $this->createdSynonyms = [];

        $this->plugin()->getIndexes()->deleteIndex($index);

        self::assertNull($this->freshSynonyms()->getSynonymById((int)$synonym->id));
    }

    public function testAGroupForAMissingIndexOrSiteFailsValidationRatherThanTheForeignKey(): void
    {
        $synonym = new Synonym(['terms' => ['boots', 'footwear'], 'indexId' => 999999]);
        self::assertFalse($this->service()->saveSynonym($synonym));
        self::assertArrayHasKey('indexId', $synonym->getErrors());

        $synonym = new Synonym(['terms' => ['boots', 'footwear'], 'siteId' => 999999]);
        self::assertFalse($this->service()->saveSynonym($synonym));
        self::assertArrayHasKey('siteId', $synonym->getErrors());
    }

    public function testAGroupScopedToOneSiteOnlyAppliesThere(): void
    {
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $this->save(['boots', 'footwear'], siteId: $siteId);

        self::assertSame(['footwear'], $this->freshSynonyms()->expand('boots', $this->index, $siteId));
        self::assertSame([], $this->freshSynonyms()->expand('boots', $this->index, $siteId + 10000));
    }

    /**
     * @param string[] $terms
     * @param string[] $replacements
     */
    private function save(
        array $terms,
        array $replacements = [],
        SynonymType $type = SynonymType::TwoWay,
        ?int $indexId = null,
        ?int $siteId = null,
    ): Synonym {
        $synonym = new Synonym([
            'indexId' => $indexId ?? $this->index->id,
            'siteId' => $siteId,
            'type' => $type,
            'terms' => $terms,
            'replacements' => $replacements,
        ]);

        self::assertTrue($this->service()->saveSynonym($synonym), implode(' ', $synonym->getErrorSummary(true)));
        $this->createdSynonyms[] = $synonym;

        return $synonym;
    }

    private function service(): Synonyms
    {
        return $this->plugin()->getSynonyms();
    }

    /**
     * A service that has memoized nothing, to prove a value really came back from the database.
     */
    private function freshSynonyms(): Synonyms
    {
        return new Synonyms();
    }
}

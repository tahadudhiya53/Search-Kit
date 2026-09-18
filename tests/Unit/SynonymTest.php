<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\SynonymType;
use Tahadudhiya\SearchKit\models\Synonym;

class SynonymTest extends TestCase
{
    public function testATwoWayGroupStandsInForEveryOtherTerm(): void
    {
        $synonym = $this->group(['boots', 'footwear', 'shoes']);

        self::assertSame(['footwear', 'shoes'], $synonym->expand('boots'));
        self::assertSame(['boots', 'shoes'], $synonym->expand('footwear'));
        self::assertSame([], $synonym->expand('sandals'));
    }

    public function testAOneWayGroupOnlyExpandsInOneDirection(): void
    {
        $synonym = $this->group(['tv'], ['television'], SynonymType::OneWay);

        self::assertSame(['television'], $synonym->expand('tv'));
        self::assertSame([], $synonym->expand('television'));
    }

    public function testADisabledGroupExpandsNothing(): void
    {
        $synonym = $this->group(['boots', 'footwear']);
        $synonym->enabled = false;

        self::assertSame([], $synonym->expand('boots'));
    }

    public function testAppliesOnlyWithinItsOwnIndexAndSite(): void
    {
        $everywhere = $this->group(['boots', 'footwear']);
        self::assertTrue($everywhere->applies(1, 2));

        $oneIndex = $this->group(['boots', 'footwear']);
        $oneIndex->indexId = 1;
        self::assertTrue($oneIndex->applies(1, 2));
        self::assertFalse($oneIndex->applies(2, 2));

        $oneSite = $this->group(['boots', 'footwear']);
        $oneSite->siteId = 2;
        self::assertTrue($oneSite->applies(1, 2));
        self::assertFalse($oneSite->applies(1, 3));

        // A search of every site the index covers is not narrowed by a group scoped to one of them.
        self::assertTrue($oneSite->applies(1, null));
    }

    public function testATwoWayGroupNeedsTwoTermsAndNoReplacements(): void
    {
        $single = $this->group(['boots']);
        self::assertFalse($single->validate());
        self::assertArrayHasKey('terms', $single->getErrors());

        $withReplacements = $this->group(['boots', 'footwear'], ['shoes']);
        self::assertFalse($withReplacements->validate());
        self::assertArrayHasKey('replacements', $withReplacements->getErrors());
    }

    public function testAOneWayGroupNeedsSomethingToExpandInto(): void
    {
        $synonym = $this->group(['tv'], [], SynonymType::OneWay);

        self::assertFalse($synonym->validate());
        self::assertArrayHasKey('replacements', $synonym->getErrors());
    }

    public function testRejectsAWordThatIsNotSearchableText(): void
    {
        $synonym = $this->group(['boots', '  ']);

        self::assertFalse($synonym->validate());
        self::assertArrayHasKey('terms', $synonym->getErrors());
    }

    public function testReadsAsOneLine(): void
    {
        self::assertSame('boots, footwear', (string)$this->group(['boots', 'footwear']));
        self::assertSame('tv → television', (string)$this->group(['tv'], ['television'], SynonymType::OneWay));
    }

    /**
     * @param string[] $terms
     * @param string[] $replacements
     */
    private function group(array $terms, array $replacements = [], SynonymType $type = SynonymType::TwoWay): Synonym
    {
        return new Synonym([
            'type' => $type,
            'terms' => $terms,
            'replacements' => $replacements,
        ]);
    }
}

<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\services\Highlighting;

class HighlightingTest extends TestCase
{
    private Highlighting $highlighting;

    protected function setUp(): void
    {
        $this->highlighting = new Highlighting();
    }

    public function testMarksEveryMatchedTerm(): void
    {
        $excerpt = $this->highlighting->excerpt('Winter boots for winter walks', ['winter']);

        self::assertSame('Winter boots for winter walks', $excerpt['snippet']);
        self::assertSame('<mark>Winter</mark> boots for <mark>winter</mark> walks', $excerpt['highlight']);
    }

    public function testMatchesWholeWordsFromTheStart(): void
    {
        $excerpt = $this->highlighting->excerpt('Waterproof boots and a bootlace', ['boot']);

        // “boot” should reach “boots” and “bootlace”, but never the middle of another word.
        self::assertSame('Waterproof <mark>boots</mark> and a <mark>bootlace</mark>', $excerpt['highlight']);
    }

    public function testReturnsNothingWhenTheValueDoesNotMatch(): void
    {
        self::assertNull($this->highlighting->excerpt('Waterproof jackets', ['boots']));
        self::assertNull($this->highlighting->excerpt('', ['boots']));
        self::assertNull($this->highlighting->excerpt('Anything at all', []));
    }

    public function testIndexedContentCannotCarryMarkupIntoASnippet(): void
    {
        $value = '<p>Winter boots</p><script>alert("x")</script> & "quoted"';

        $excerpt = $this->highlighting->excerpt($value, ['winter']);

        self::assertStringNotContainsString('<script>', $excerpt['highlight']);
        self::assertStringNotContainsString('<p>', $excerpt['highlight']);
        self::assertStringContainsString('<mark>Winter</mark>', $excerpt['highlight']);
        self::assertStringContainsString('&amp;', $excerpt['highlight']);
        self::assertStringContainsString('&quot;quoted&quot;', $excerpt['highlight']);
    }

    public function testSnippetsAreCutAroundTheMatch(): void
    {
        $value = str_repeat('padding ', 40) . 'winter boots ' . str_repeat('trailing ', 40);

        $excerpt = $this->highlighting->excerpt($value, ['winter'], 60);

        self::assertStringContainsString('winter boots', $excerpt['snippet']);
        self::assertStringStartsWith('…', $excerpt['snippet']);
        self::assertStringEndsWith('…', $excerpt['snippet']);
        self::assertLessThanOrEqual(62, mb_strlen($excerpt['snippet']));
        self::assertStringNotContainsString('paddin ', $excerpt['snippet'], 'Words should not be cut in half.');
    }

    public function testAShortValueIsKeptWhole(): void
    {
        $excerpt = $this->highlighting->excerpt('Winter boots', ['boots'], 200);

        self::assertSame('Winter boots', $excerpt['snippet']);
    }

    public function testMarksEveryTermOfAMultiWordQuery(): void
    {
        $excerpt = $this->highlighting->excerpt('Winter boots in the snow', ['winter', 'snow']);

        self::assertSame('<mark>Winter</mark> boots in the <mark>snow</mark>', $excerpt['highlight']);
    }

    public function testHandlesTermsAtBothEndsOfTheValue(): void
    {
        $start = $this->highlighting->excerpt('Boots for sale', ['boots']);
        $end = $this->highlighting->excerpt('For sale: boots', ['boots']);

        self::assertStringStartsWith('<mark>Boots</mark>', $start['highlight']);
        self::assertStringEndsWith('<mark>boots</mark>', $end['highlight']);
    }

    public function testMatchesAccentedAndNonLatinText(): void
    {
        $excerpt = $this->highlighting->excerpt('Zimní boty a sníh', ['zimní']);
        self::assertSame('<mark>Zimní</mark> boty a sníh', $excerpt['highlight']);

        $japanese = $this->highlighting->excerpt('冬のブーツ', ['ブーツ']);
        self::assertSame('冬の<mark>ブーツ</mark>', $japanese['highlight']);
    }

    public function testKeepsWholeWordsWhenCuttingUnicodeText(): void
    {
        $value = str_repeat('příliš žluťoučký kůň ', 20) . 'zimní boty';

        $excerpt = $this->highlighting->excerpt($value, ['zimní'], 40);

        self::assertStringContainsString('zimní boty', $excerpt['snippet']);
        self::assertLessThanOrEqual(42, mb_strlen($excerpt['snippet']));
    }

    public function testAnEncodedPayloadStaysEncoded(): void
    {
        $excerpt = $this->highlighting->excerpt('Winter &lt;script&gt;alert(1)&lt;/script&gt; boots', ['winter']);

        self::assertStringNotContainsString('<script>', $excerpt['highlight']);
        self::assertStringContainsString('&lt;script&gt;', $excerpt['highlight']);
    }

    public function testAnImageOnErrorPayloadCannotEscape(): void
    {
        $excerpt = $this->highlighting->excerpt('<img src=x onerror="alert(1)"> winter', ['winter']);

        self::assertStringNotContainsString('onerror', $excerpt['highlight']);
        self::assertSame('<mark>winter</mark>', $excerpt['highlight']);
    }

    public function testAVeryLongValueIsStillCutToTheRequestedLength(): void
    {
        $value = str_repeat('padding ', 20000) . 'winter';

        $excerpt = $this->highlighting->excerpt($value, ['winter'], 100);

        self::assertLessThanOrEqual(102, mb_strlen($excerpt['snippet']));
        self::assertStringContainsString('winter', $excerpt['snippet']);
    }

    public function testAnEmptyFieldValueIsNotAnExcerpt(): void
    {
        self::assertNull($this->highlighting->excerpt('   ', ['winter']));
        self::assertNull($this->highlighting->excerpt('<p></p>', ['winter']));
    }

    public function testCollapsesWhitespaceSoSnippetsReadAsText(): void
    {
        $excerpt = $this->highlighting->excerpt("Winter\n\n   boots", ['boots']);

        self::assertSame('Winter boots', $excerpt['snippet']);
    }
}

<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\SearchIntent;
use Tahadudhiya\SearchKit\services\Intent;

/**
 * What a query's wording is read as, and — more importantly — what it is not read as.
 */
class IntentTest extends TestCase
{
    public function testACueWordIsWhatDecidesTheReadingAndIsReportedWithIt(): void
    {
        $intent = (new Intent())->classify('how do I clean a kettle', 'en-GB');

        self::assertSame(SearchIntent::Informational, $intent->intent);
        self::assertSame(['how'], $intent->getMatchedCues());
        self::assertStringContainsString('“how”', $intent->getExplanation());
    }

    public function testEachKindOfWordingIsReadAsWhatItSays(): void
    {
        $intent = new Intent();

        self::assertSame(SearchIntent::Transactional, $intent->classify('kettle delivery cost', 'en')->intent);
        self::assertSame(SearchIntent::Support, $intent->classify('kettle warranty', 'en')->intent);
        self::assertSame(SearchIntent::Product, $intent->classify('kettle spare parts', 'en')->intent);
    }

    public function testAPartNumberIsReadAsAProductWhateverLanguageItSitsIn(): void
    {
        $intent = (new Intent())->classify('kw-3200x', 'de');

        self::assertSame(SearchIntent::Product, $intent->intent);
        self::assertSame(['kw-3200x'], $intent->getMatchedCues());
    }

    public function testAQuerySayingNothingRecognisableIsLeftUnread(): void
    {
        $intent = (new Intent())->classify('blue kettle', 'en');

        self::assertSame(SearchIntent::Unknown, $intent->intent);
        self::assertFalse($intent->ambiguous);
        self::assertSame([], $intent->getMatchedCues());
        self::assertStringContainsString('Nothing in the wording', $intent->getExplanation());
    }

    public function testAQuerySayingTwoThingsEquallyIsReportedAsSayingNeither(): void
    {
        $intent = (new Intent())->classify('kettle price help', 'en');

        self::assertSame(SearchIntent::Unknown, $intent->intent, 'A tie is not resolved by some order of precedence.');
        self::assertTrue($intent->ambiguous);
        self::assertStringContainsString('Reads as both', $intent->getExplanation());
    }

    public function testMoreCuesForOneReadingSettleIt(): void
    {
        $intent = (new Intent())->classify('kettle price delivery help', 'en');

        self::assertSame(SearchIntent::Transactional, $intent->intent);
        self::assertSame(['price', 'delivery'], $intent->getMatchedCues());
    }

    public function testTheEnglishCueWordsAreNotReadIntoAnotherLanguage(): void
    {
        // “rent” is a cue in English and an ordinary German word, so reading it either way would
        // be reading a German query with an English dictionary.
        $english = (new Intent())->classify('rent a kettle', 'en');
        $german = (new Intent())->classify('rent a kettle', 'de');

        self::assertSame(SearchIntent::Transactional, $english->intent);
        self::assertSame(SearchIntent::Unknown, $german->intent);
    }

    public function testAWordWithANumberStuckOnItIsNotAPartNumber(): void
    {
        self::assertSame(SearchIntent::Unknown, (new Intent())->classify('a1', 'en')->intent);
    }
}

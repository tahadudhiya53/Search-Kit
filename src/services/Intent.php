<?php

namespace Tahadudhiya\SearchKit\services;

use Tahadudhiya\SearchKit\enums\SearchIntent;
use Tahadudhiya\SearchKit\models\QueryIntent;
use yii\base\Component;

/**
 * What a query appears to be after, read from the wording and nothing else. It is a dictionary of
 * cue words plus one structural signal, so every reading can be shown the words it came from — and
 * a query saying nothing recognisable is left unread rather than guessed at.
 *
 * Nothing here touches a search: intent is a way of reading what has been recorded, never an input
 * to ranking.
 */
class Intent extends Component
{
    /**
     * @var array<string,string[]> Intent => the words that point at it. The list is English, so it
     * is only read for a query recorded in English, exactly as the built-in stop words are.
     */
    public const CUES = [
        SearchIntent::Product->value => [
            'product', 'products', 'model', 'models', 'range', 'spec', 'specs', 'specification',
            'size', 'sizes', 'colour', 'colours', 'color', 'colors', 'stock', 'part', 'parts',
            'spare', 'spares', 'accessory', 'accessories',
        ],
        SearchIntent::Informational->value => [
            'how', 'what', 'why', 'when', 'where', 'who', 'which', 'guide', 'guides', 'tutorial',
            'explain', 'explained', 'difference', 'meaning', 'tips', 'examples', 'about', 'learn',
            'vs', 'versus',
        ],
        SearchIntent::Support->value => [
            'help', 'support', 'contact', 'problem', 'problems', 'issue', 'issues', 'broken',
            'fix', 'error', 'faq', 'faqs', 'warranty', 'repair', 'complaint', 'complaints',
            'return', 'returns', 'refund', 'refunds', 'cancel', 'cancellation', 'troubleshooting',
        ],
        SearchIntent::Transactional->value => [
            'buy', 'order', 'orders', 'price', 'prices', 'pricing', 'cost', 'costs', 'checkout',
            'basket', 'cart', 'delivery', 'shipping', 'discount', 'voucher', 'coupon', 'sale',
            'subscribe', 'subscription', 'book', 'booking', 'quote', 'hire', 'rent', 'payment',
        ],
    ];

    /** @var int The shortest a token may be and still read as a product code. */
    private const MIN_CODE_LENGTH = 4;

    /**
     * How this query reads. A query pointing equally at two intents is reported as ambiguous rather
     * than resolved by some order of precedence, since there is nothing in it to break the tie.
     *
     * @param string|null $language The language the query was recorded in. The cue words are
     *                              English, so anything else is read from its structure alone.
     *                              Null reads the query as English.
     */
    public function classify(string $text, ?string $language = null): QueryIntent
    {
        $tokens = $this->tokenize($text);
        $cues = [];

        foreach ($tokens as $token) {
            // A token carrying both letters and digits is a code, whatever language it sits in.
            if ($this->looksLikeCode($token)) {
                $cues[SearchIntent::Product->value][] = $token;
            }
        }

        if (self::isEnglish($language)) {
            foreach (self::CUES as $intent => $words) {
                foreach (array_intersect($tokens, $words) as $token) {
                    $cues[$intent][] = $token;
                }
            }
        }

        $cues = array_map(static fn(array $matched) => array_values(array_unique($matched)), $cues);

        return new QueryIntent([
            'intent' => $this->resolve($cues),
            'cues' => $cues,
            'ambiguous' => $this->isTied($cues),
        ]);
    }

    /**
     * The intent the most cues point at, or none at all when two point equally.
     *
     * @param array<string,string[]> $cues
     */
    private function resolve(array $cues): SearchIntent
    {
        if ($cues === [] || $this->isTied($cues)) {
            return SearchIntent::Unknown;
        }

        $counts = array_map('count', $cues);
        arsort($counts);

        return SearchIntent::from((string)array_key_first($counts));
    }

    /**
     * Whether two intents are pointed at by the same number of cues, which is a query that says
     * two things at once.
     *
     * @param array<string,string[]> $cues
     */
    private function isTied(array $cues): bool
    {
        if (count($cues) < 2) {
            return false;
        }

        $counts = array_map('count', $cues);
        arsort($counts);
        $counts = array_values($counts);

        return $counts[0] === $counts[1];
    }

    /**
     * The query's words, lowercased. Hyphens are kept inside a token so a part number survives as
     * one thing rather than becoming a word and a number.
     *
     * @return string[]
     */
    private function tokenize(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}\-]+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map(static fn(string $token) => trim($token, '-'), $tokens)));
    }

    /**
     * Whether a token reads as a part or model number: letters and digits together, long enough
     * that a word with a number stuck on it is not mistaken for one.
     */
    private function looksLikeCode(string $token): bool
    {
        return mb_strlen($token) >= self::MIN_CODE_LENGTH
            && preg_match('/\p{L}/u', $token) === 1
            && preg_match('/\p{N}/u', $token) === 1;
    }

    /**
     * Whether the cue words are written for the language this query was recorded in.
     */
    private static function isEnglish(?string $language): bool
    {
        return $language === null || str_starts_with(strtolower($language), 'en');
    }
}

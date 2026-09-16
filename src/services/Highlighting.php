<?php

namespace Tahadudhiya\SearchKit\services;

use Craft;
use craft\helpers\Html;
use Tahadudhiya\SearchKit\models\SearchHit;
use Tahadudhiya\SearchKit\models\SearchIndex;
use Tahadudhiya\SearchKit\models\SearchQuery;
use Tahadudhiya\SearchKit\models\SearchResult;
use Tahadudhiya\SearchKit\SearchKit;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Works out what a hit matched on, from the index's own searchable values. It only fills in what a
 * provider left empty, so a provider that highlights for itself keeps its own answer.
 */
class Highlighting extends Component
{
    private ?Documents $_documents = null;

    /**
     * Highlights the hits on the page. Nothing here is allowed to fail a search: an excerpt is a
     * presentation detail, so a value that cannot be read is logged and skipped.
     */
    public function apply(SearchResult $result, SearchQuery $query, SearchIndex $index): void
    {
        $tokens = $query->getTokens();

        if ($tokens === []) {
            return;
        }

        foreach ($result->hits as $hit) {
            if ($hit->highlights !== [] || $hit->element === null) {
                continue;
            }

            try {
                $this->highlightHit($hit, $index, $tokens, $query->snippetLength);
            } catch (Throwable $e) {
                Craft::warning(
                    "Could not build an excerpt for element {$hit->elementId}: {$e->getMessage()}",
                    SearchKit::LOG_CATEGORY,
                );
            }
        }
    }

    /**
     * @param string[] $tokens
     */
    private function highlightHit(SearchHit $hit, SearchIndex $index, array $tokens, int $length): void
    {
        $element = $hit->element;

        if ($element === null) {
            return;
        }

        $document = $this->getDocuments()->buildDocument($index, $element);
        $weights = $document->getWeights();
        arsort($weights);

        foreach (array_keys($weights) as $handle) {
            $excerpt = $this->excerpt((string)$document->getValue($handle), $tokens, $length);

            if ($excerpt === null) {
                continue;
            }

            $hit->snippets[$handle] = $excerpt['snippet'];
            $hit->highlights[$handle] = $excerpt['highlight'];
        }

        if ($hit->matchedFields === []) {
            $hit->matchedFields = array_keys($hit->highlights);
        }
    }

    /**
     * An excerpt of the value around its first matched term, as plain text and with the matches
     * marked. Returns null when the value does not contain any of the terms.
     *
     * @param string[] $tokens
     * @return array{snippet:string,highlight:string}|null
     */
    public function excerpt(string $value, array $tokens, int $length = 200): ?array
    {
        $text = $this->plainText($value);

        if ($text === '') {
            return null;
        }

        foreach ($this->patterns($tokens) as $pattern) {
            if (!preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $snippet = $this->window($text, mb_strlen(substr($text, 0, (int)$match[0][1])), $length);

            return [
                'snippet' => $snippet,
                'highlight' => $this->mark($snippet, $pattern),
            ];
        }

        return null;
    }

    /**
     * Values are keywords a Craft field produced, which may still carry markup a snippet should not.
     */
    private function plainText(string $value): string
    {
        $stripped = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string)preg_replace('/\s+/u', ' ', $stripped));
    }

    /**
     * Terms are looked for at the start of a word first, so “boot” highlights “boots” rather than
     * the middle of “reboots”. Failing that they are looked for anywhere, which is the only way to
     * highlight a language that does not put spaces between its words.
     *
     * @param string[] $tokens
     * @return string[]
     */
    private function patterns(array $tokens): array
    {
        $quoted = array_map(static fn(string $token) => preg_quote($token, '/'), array_filter($tokens));

        if ($quoted === []) {
            return [];
        }

        $terms = implode('|', $quoted);

        return [
            '/(?<![\p{L}\p{N}])(?:' . $terms . ')[\p{L}\p{N}]*/iu',
            '/(?:' . $terms . ')/iu',
        ];
    }

    /**
     * A window of the text around the match, cut at word boundaries rather than mid-word.
     */
    private function window(string $text, int $matchOffset, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $start = max(0, $matchOffset - (int)floor($length / 3));
        $window = mb_substr($text, $start, $length);

        if ($start > 0 && ($space = mb_strpos($window, ' ')) !== false) {
            $window = mb_substr($window, $space + 1);
        }

        if ($start + $length < mb_strlen($text) && ($space = mb_strrpos($window, ' ')) !== false) {
            $window = mb_substr($window, 0, $space);
        }

        return ($start > 0 ? '…' : '') . $window . ($start + $length < mb_strlen($text) ? '…' : '');
    }

    /**
     * Every part is escaped before the marks go in, so no indexed content can carry markup out.
     */
    private function mark(string $snippet, string $pattern): string
    {
        $parts = preg_split($pattern, $snippet, -1, PREG_SPLIT_OFFSET_CAPTURE);
        $marked = '';
        $cursor = 0;

        foreach ($parts ?: [] as $part) {
            [$text, $offset] = $part;

            if ($offset > $cursor) {
                $matched = substr($snippet, $cursor, $offset - $cursor);
                $marked .= Html::tag('mark', Html::encode($matched));
            }

            $marked .= Html::encode($text);
            $cursor = $offset + strlen($text);
        }

        if ($cursor < strlen($snippet)) {
            $marked .= Html::tag('mark', Html::encode(substr($snippet, $cursor)));
        }

        return $marked;
    }

    public function setDocuments(Documents $documents): void
    {
        $this->_documents = $documents;
    }

    public function getDocuments(): Documents
    {
        return $this->_documents ??= SearchKit::getInstance()?->getDocuments()
            ?? throw new InvalidConfigException('SearchKit is not installed or is disabled.');
    }
}

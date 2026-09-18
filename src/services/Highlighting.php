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
    /** @var int Excerpts shown per field, so a long value is not judged by its first match alone. */
    private const MAX_WINDOWS = 3;

    /** @var int Less text than this around a match reads as fragments rather than as a sentence. */
    private const MIN_WINDOW = 60;

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
     * An excerpt of the value around the terms it matched, as plain text and with the matches
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
            if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $snippet = $this->windows($text, $this->characterOffsets($text, $matches[0]), $length);

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

        // Longest first, so a phrase is marked as one rather than word by word.
        usort($quoted, static fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $terms = implode('|', $quoted);

        return [
            '/(?<![\p{L}\p{N}])(?:' . $terms . ')[\p{L}\p{N}]*/iu',
            '/(?:' . $terms . ')/iu',
        ];
    }

    /**
     * Matches are reported in bytes, and an excerpt is cut in characters.
     *
     * @param array<array{string,int}> $matches
     * @return int[]
     */
    private function characterOffsets(string $text, array $matches): array
    {
        return array_map(
            static fn(array $match) => mb_strlen(substr($text, 0, (int)$match[1])),
            $matches,
        );
    }

    /**
     * The text around the matches, cut at word boundaries. A value long enough to hold several
     * matches shows more than the first one, so a snippet reflects why the whole value matched.
     *
     * @param int[] $offsets Where each match starts, in characters, in the order they appear.
     */
    private function windows(string $text, array $offsets, int $length): string
    {
        $total = mb_strlen($text);

        if ($total <= $length || $offsets === []) {
            return $text;
        }

        $count = max(1, min(self::MAX_WINDOWS, intdiv($length, self::MIN_WINDOW)));
        $size = max(self::MIN_WINDOW, intdiv($length, $count));
        $parts = [];
        $covered = 0;
        $leading = false;

        foreach ($offsets as $offset) {
            if (count($parts) >= $count) {
                break;
            }

            // A match already inside the text taken so far is shown by it.
            if ($offset < $covered) {
                continue;
            }

            $start = max($covered, $offset - intdiv($size, 3));
            $end = min($total, $start + $size);
            $window = mb_substr($text, $start, $end - $start);

            if ($start > 0) {
                $window = $this->fromWordBoundary($window);
                $leading = $leading || $parts === [];
            }

            if ($end < $total) {
                $window = $this->toWordBoundary($window);
            }

            $parts[] = $window;
            $covered = $end;
        }

        return ($leading ? '…' : '') . implode(' … ', $parts) . ($covered < $total ? '…' : '');
    }

    /**
     * Drops the part of a word the window opened in the middle of.
     */
    private function fromWordBoundary(string $window): string
    {
        $space = mb_strpos($window, ' ');

        return $space !== false ? mb_substr($window, $space + 1) : $window;
    }

    private function toWordBoundary(string $window): string
    {
        $space = mb_strrpos($window, ' ');

        return $space !== false ? mb_substr($window, 0, $space) : $window;
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

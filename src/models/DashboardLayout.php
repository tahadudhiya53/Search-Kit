<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use craft\helpers\Json;

/**
 * How one person arranged their dashboard: which panels they see, in what order, and how wide each
 * one is. A panel the arrangement has never heard of is added at its default width, so a dashboard
 * that grows a panel shows it to everybody without anyone rearranging anything.
 */
class DashboardLayout extends Model
{
    /** @var int Columns the dashboard is divided into, which is the widest a panel can be. */
    public const COLUMNS = 4;

    /** @var array<string,int> Every panel there is, in the order a new arrangement starts in. */
    public const PANELS = [
        'overview' => 4,
        'trend' => 3,
        'outcomes' => 1,
        'popular' => 2,
        'zeroResults' => 2,
        'clicked' => 2,
        'unopened' => 2,
        'performance' => 2,
        'slowQueries' => 2,
        'health' => 4,
    ];

    /** @var array<string,int> Panel => width, in the order they are shown. */
    private array $_spans = [];

    /** @var string[] Panels this person has put away. */
    private array $_hidden = [];

    /**
     * Whatever was stored, which may predate a panel or have been edited by hand. Anything that
     * cannot be read falls back to the default arrangement rather than failing to open the page.
     */
    public static function fromConfig(?string $stored): self
    {
        $layout = new self();
        $config = $stored !== null && $stored !== '' ? Json::decodeIfJson($stored) : null;

        if (is_array($config)) {
            foreach ($config['panels'] ?? [] as $key => $span) {
                if (is_string($key) && isset(self::PANELS[$key]) && (is_int($span) || is_string($span))) {
                    $layout->_spans[$key] = self::readSpan($span);
                }
            }

            foreach ($config['hidden'] ?? [] as $key) {
                if (is_string($key) && isset(self::PANELS[$key])) {
                    $layout->_hidden[] = $key;
                }
            }
        }

        return $layout->fill();
    }

    /**
     * Puts the panels in this order, keeping each one's width. Anything not named keeps its place
     * after them, so a request that knows about fewer panels cannot lose the rest.
     *
     * @param string[] $keys
     */
    public function reorder(array $keys): void
    {
        $ordered = [];

        foreach ($keys as $key) {
            if (isset($this->_spans[$key])) {
                $ordered[$key] = $this->_spans[$key];
            }
        }

        $this->_spans = $ordered + $this->_spans;
        $this->fill();
    }

    public function setSpan(string $key, int|string $span): void
    {
        if (isset($this->_spans[$key])) {
            $this->_spans[$key] = self::readSpan($span);
        }
    }

    public function setHidden(string $key, bool $hidden): void
    {
        if (!isset(self::PANELS[$key])) {
            return;
        }

        $this->_hidden = array_values(array_diff($this->_hidden, [$key]));

        if ($hidden) {
            $this->_hidden[] = $key;
        }
    }

    /**
     * @return array<string,int> Panel => width, in order, for the panels being shown.
     */
    public function getVisiblePanels(): array
    {
        return array_diff_key($this->_spans, array_flip($this->_hidden));
    }

    /**
     * @return string[] The panels put away, in the order they would come back in.
     */
    public function getHiddenPanels(): array
    {
        return array_values(array_intersect(array_keys($this->_spans), $this->_hidden));
    }

    public function getSpan(string $key): int
    {
        return $this->_spans[$key] ?? self::PANELS[$key] ?? 1;
    }

    /**
     * @return array<string,mixed>
     */
    public function toConfig(): array
    {
        return ['panels' => $this->_spans, 'hidden' => $this->getHiddenPanels()];
    }

    /**
     * Whether this is still the arrangement everybody starts with.
     */
    public function isDefault(): bool
    {
        return $this->_hidden === [] && $this->_spans === self::PANELS;
    }

    /**
     * Every panel there is, each with a width, in the order they are shown.
     */
    private function fill(): self
    {
        foreach (self::PANELS as $key => $span) {
            $this->_spans[$key] ??= $span;
        }

        return $this;
    }

    private static function readSpan(int|string $span): int
    {
        return max(1, min(self::COLUMNS, (int)$span));
    }
}

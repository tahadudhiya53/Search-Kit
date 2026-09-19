<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use DateTime;
use Throwable;

/**
 * Which recorded searches a metric is read from. Everything is optional: with nothing set, a metric
 * describes every index over the whole period that is still kept.
 */
class InsightsCriteria extends Model
{
    public const MAX_LIMIT = 200;

    public ?int $indexId = null;

    /** @var int|null Only searches of this site. A search of every site is not one of them. */
    public ?int $siteId = null;

    /** @var DateTime|null The earliest search counted, inclusive. */
    public ?DateTime $dateFrom = null;

    /** @var DateTime|null The moment counting stops, exclusive, so a whole day can be asked for. */
    public ?DateTime $dateTo = null;

    /** @var int How many rows a grouped metric returns. */
    public int $limit = 25;

    /** @var int Searches a query needs before it counts as a gap rather than a one-off. */
    public int $minSearches = 2;

    /** @var int Milliseconds beyond which a search counts as slow. */
    public int $slowThreshold = 500;

    /**
     * What a control panel form posted. A date that cannot be read is left unset rather than
     * refusing the page, since a filter is not a saved setting.
     *
     * A day is asked for as a whole day: “to the 18th” counts everything that happened on the 18th,
     * which is why the far end is the start of the day after rather than the start of the 18th.
     *
     * @param array<string,mixed> $params
     */
    public static function fromRequest(array $params): self
    {
        $criteria = new self();

        $criteria->indexId = self::readInteger($params['indexId'] ?? null);
        $criteria->siteId = self::readInteger($params['siteId'] ?? null);
        $criteria->dateFrom = self::readDate($params['dateFrom'] ?? null)?->setTime(0, 0);
        $criteria->dateTo = self::readDate($params['dateTo'] ?? null)?->setTime(0, 0)->modify('+1 day');
        $criteria->limit = min(self::MAX_LIMIT, max(1, self::readInteger($params['limit'] ?? null) ?? $criteria->limit));

        return $criteria;
    }

    /**
     * The day the far end was asked for, which is the day before counting stops. This is what a
     * filter shows back, since nobody asked for the day after.
     */
    public function getSelectedDateTo(): ?DateTime
    {
        return $this->dateTo !== null ? (clone $this->dateTo)->modify('-1 day') : null;
    }

    /**
     * A key describing exactly this reading, so two different filters can never share a cache entry.
     */
    public function cacheKey(string $metric): string
    {
        return implode(':', [
            'searchkit:insights',
            $metric,
            $this->indexId ?? '*',
            $this->siteId ?? '*',
            $this->dateFrom?->getTimestamp() ?? '*',
            $this->dateTo?->getTimestamp() ?? '*',
            $this->limit,
            $this->minSearches,
            $this->slowThreshold,
        ]);
    }

    private static function readInteger(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        $integer = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        return $integer === false ? null : $integer;
    }

    private static function readDate(mixed $value): ?DateTime
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        try {
            // A day picked in the control panel is the day it was in the system timezone, not in UTC.
            $date = DateTimeHelper::toDateTime($value, true);
        } catch (Throwable) {
            return null;
        }

        return $date === false ? null : $date;
    }
}

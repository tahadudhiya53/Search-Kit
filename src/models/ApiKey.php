<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;
use DateTime;

/**
 * A key the search API is reached with. The key itself is never held here: only what it hashes to,
 * and the opening characters that tell one key from another once it has been shown.
 */
class ApiKey extends Model
{
    /** @var int How many characters of a key are kept so it can still be recognized. */
    public const PREFIX_LENGTH = 8;

    /** @var int The most requests a minute a key may be given. */
    public const MAX_RATE_LIMIT = 100000;

    public ?int $id = null;
    public string $name = '';

    /** @var string The digest of the key, which is the only form of it that is stored. */
    public string $hash = '';

    /** @var string The key's opening characters, for telling keys apart in the control panel. */
    public string $prefix = '';

    public bool $enabled = true;

    /** @var int[] The indexes this key may search. Empty means every index. */
    public array $indexIds = [];

    /** @var int|null Requests a minute this key may make, or null for no limit of its own. */
    public ?int $rateLimit = null;

    public ?DateTime $dateLastUsed = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function coversAllIndexes(): bool
    {
        return $this->indexIds === [];
    }

    /**
     * Whether this key may search an index. A key naming no index covers every one of them.
     */
    public function coversIndex(int $indexId): bool
    {
        return $this->coversAllIndexes() || in_array($indexId, $this->indexIds, true);
    }

    public function hasRateLimit(): bool
    {
        return $this->rateLimit !== null && $this->rateLimit > 0;
    }

    /**
     * How the key is referred to once it can no longer be shown.
     */
    public function getMaskedKey(): string
    {
        return $this->prefix . '…';
    }

    protected function defineRules(): array
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['rateLimit'], 'integer', 'min' => 1, 'max' => self::MAX_RATE_LIMIT],
            [['indexIds'], 'validateIndexIds'],
        ];
    }

    public function validateIndexIds(string $attribute): void
    {
        foreach ($this->indexIds as $indexId) {
            if ($indexId < 1) {
                $this->addError($attribute, 'Each index must be given as an ID.');
                return;
            }
        }
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->getMaskedKey();
    }
}

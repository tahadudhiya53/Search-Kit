<?php

namespace Tahadudhiya\SearchKit\models;

use craft\base\Model;

/**
 * A provider's self-reported health. Never carries credentials or raw connection details.
 */
class ProviderStatus extends Model
{
    public bool $available = false;
    public ?string $message = null;

    /** @var array<string,mixed> */
    public array $details = [];

    public static function available(?string $message = null, array $details = []): self
    {
        return new self([
            'available' => true,
            'message' => $message,
            'details' => $details,
        ]);
    }

    public static function unavailable(string $message, array $details = []): self
    {
        return new self([
            'available' => false,
            'message' => $message,
            'details' => $details,
        ]);
    }
}

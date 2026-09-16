<?php

namespace Tahadudhiya\SearchKit\errors;

/**
 * Carries the failed validation messages so a caller can report them without re-validating.
 */
class InvalidQueryException extends SearchKitException
{
    /**
     * @param array<string,string[]> $errors
     */
    public function __construct(string $message, private readonly array $errors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string,string[]>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getName(): string
    {
        return 'Invalid Search Query';
    }
}

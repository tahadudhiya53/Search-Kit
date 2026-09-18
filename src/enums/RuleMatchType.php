<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * How a rule decides whether it governs a query. Patterns are `*` wildcards rather than regular
 * expressions, so nothing an administrator types can be made to run away on a search request.
 */
enum RuleMatchType: string
{
    case Exact = 'exact';
    case Contains = 'contains';
    case StartsWith = 'startsWith';
    case EndsWith = 'endsWith';
    case Wildcard = 'wildcard';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Is exactly',
            self::Contains => 'Contains',
            self::StartsWith => 'Starts with',
            self::EndsWith => 'Ends with',
            self::Wildcard => 'Matches pattern',
        };
    }

    /**
     * Both texts are already normalized by the time they reach here, so a rule written with capital
     * letters or accents still governs the query someone actually typed.
     */
    public function matches(string $query, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($this) {
            self::Exact => $query === $value,
            self::Contains => str_contains($query, $value),
            self::StartsWith => str_starts_with($query, $value),
            self::EndsWith => str_ends_with($query, $value),
            self::Wildcard => (bool)preg_match($this->pattern($value), $query),
        };
    }

    /**
     * A `*` wildcard becomes the only unescaped character in the pattern, so the rule can never
     * express anything beyond “any run of characters here”.
     */
    private function pattern(string $value): string
    {
        $quoted = implode('.*', array_map(
            static fn(string $part) => preg_quote($part, '/'),
            explode('*', $value),
        ));

        return '/^' . $quoted . '$/u';
    }
}

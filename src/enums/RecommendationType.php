<?php

namespace Tahadudhiya\SearchKit\enums;

/**
 * What somebody could do about what the recorded activity shows. Every recommendation is a
 * suggestion to an administrator; nothing here changes a search on its own.
 */
enum RecommendationType: string
{
    case CreateSynonym = 'createSynonym';
    case CreateRule = 'createRule';
    case ImproveContent = 'improveContent';
    case PromoteResult = 'promoteResult';

    public function label(): string
    {
        return match ($this) {
            self::CreateSynonym => 'Create a synonym',
            self::CreateRule => 'Create a rule',
            self::ImproveContent => 'Improve the content',
            self::PromoteResult => 'Promote a result',
        };
    }
}

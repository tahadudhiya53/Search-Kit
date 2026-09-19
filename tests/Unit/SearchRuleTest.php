<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\enums\RuleActionType;
use Tahadudhiya\SearchKit\enums\RuleMatchType;
use Tahadudhiya\SearchKit\models\RuleAction;
use Tahadudhiya\SearchKit\models\SearchRule;

/**
 * What a rule refuses to be. Everything here is a mistake that would otherwise reach a search.
 */
class SearchRuleTest extends TestCase
{
    private const ENTRY = 'craft\elements\Entry';

    public function testARuleNeedsAnIndexQueryTextAndSomethingToDo(): void
    {
        $rule = new SearchRule();

        self::assertFalse($rule->validate());
        self::assertArrayHasKey('indexId', $rule->getErrors());
        self::assertArrayHasKey('matchValue', $rule->getErrors());
        self::assertArrayHasKey('actions', $rule->getErrors());
    }

    public function testAPatternOfNothingButWildcardsIsRefused(): void
    {
        $rule = $this->rule(['matchType' => RuleMatchType::Wildcard, 'matchValue' => '**']);

        self::assertFalse($rule->validate());
        self::assertArrayHasKey('matchValue', $rule->getErrors());
    }

    public function testARuleCannotEndBeforeItStarts(): void
    {
        $rule = $this->rule([
            'dateStart' => new DateTime('2026-02-01', new DateTimeZone('UTC')),
            'dateEnd' => new DateTime('2026-01-01', new DateTimeZone('UTC')),
        ]);

        self::assertFalse($rule->validate());
        self::assertArrayHasKey('dateEnd', $rule->getErrors());
    }

    public function testAScheduleWithNoDatesIsAlwaysInForce(): void
    {
        self::assertTrue($this->rule()->isInForce());
    }

    /**
     * @dataProvider unsafeRedirects
     */
    public function testARedirectMayOnlyBeAPathOrAnHttpAddress(string $value, bool $usable): void
    {
        $action = new RuleAction(['type' => RuleActionType::Redirect, 'value' => $value]);

        self::assertSame($usable, $action->validate(), $value);
    }

    /**
     * @return array<string,array{string,bool}>
     */
    public static function unsafeRedirects(): array
    {
        return [
            'path' => ['/support', true],
            'https' => ['https://example.com/support', true],
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,<script></script>', false],
            'protocol relative' => ['//evil.example.com', false],
            'bare host' => ['evil.example.com', false],
            'empty' => ['  ', false],
        ];
    }

    /**
     * @dataProvider nonsensicalActions
     * @param array<string,mixed> $config
     */
    public function testAnActionRefusesACombinationThatCannotMeanAnything(array $config, string $attribute): void
    {
        $action = new RuleAction($config + ['elementType' => self::ENTRY]);

        self::assertFalse($action->validate());
        self::assertArrayHasKey($attribute, $action->getErrors());
    }

    /**
     * @return array<string,array{array<string,mixed>,string}>
     */
    public static function nonsensicalActions(): array
    {
        return [
            'pin with no position' => [
                ['type' => RuleActionType::Pin, 'elementId' => 1, 'siteId' => 1], 'position',
            ],
            'pin at position zero' => [
                ['type' => RuleActionType::Pin, 'elementId' => 1, 'siteId' => 1, 'position' => 0], 'position',
            ],
            'pin beyond the furthest position' => [
                ['type' => RuleActionType::Pin, 'elementId' => 1, 'siteId' => 1,
                    'position' => RuleAction::MAX_POSITION + 1, ], 'position',
            ],
            'pin with no site to land in' => [
                ['type' => RuleActionType::Pin, 'elementId' => 1, 'position' => 1], 'elementId',
            ],
            'promotion with no site to land in' => [
                ['type' => RuleActionType::Promote, 'elementId' => 1], 'elementId',
            ],
            'boost with no amount' => [
                ['type' => RuleActionType::Boost, 'elementId' => 1], 'amount',
            ],
            'boost with a negative amount' => [
                ['type' => RuleActionType::Boost, 'elementId' => 1, 'amount' => -5.0], 'amount',
            ],
            'boost beyond the largest amount' => [
                ['type' => RuleActionType::Boost, 'elementId' => 1, 'amount' => RuleAction::MAX_AMOUNT + 1], 'amount',
            ],
            'boost carrying a pinned position' => [
                ['type' => RuleActionType::Boost, 'elementId' => 1, 'amount' => 5.0, 'position' => 2], 'position',
            ],
            'hide with no result' => [
                ['type' => RuleActionType::Hide], 'elementId',
            ],
            'hide carrying an amount' => [
                ['type' => RuleActionType::Hide, 'elementId' => 1, 'amount' => 5.0], 'amount',
            ],
            'redirect carrying a result' => [
                ['type' => RuleActionType::Redirect, 'value' => '/support', 'elementId' => 1], 'elementId',
            ],
            'boost carrying a redirect' => [
                ['type' => RuleActionType::Boost, 'elementId' => 1, 'amount' => 5.0, 'value' => '/support'], 'value',
            ],
        ];
    }

    public function testAnActionOnAResultRefusesAnElementTypeThatIsNotOne(): void
    {
        $hide = new RuleAction(['type' => RuleActionType::Hide, 'elementId' => 1, 'elementType' => 'Evil\\Payload']);

        self::assertFalse($hide->validate());
        self::assertArrayHasKey('elementId', $hide->getErrors());
    }

    /**
     * @param array<string,mixed> $config
     */
    private function rule(array $config = []): SearchRule
    {
        $rule = new SearchRule($config + [
            'indexId' => 1,
            'name' => 'Test rule',
            'matchValue' => 'shoes',
        ]);

        $rule->setActions([
            new RuleAction([
                'type' => RuleActionType::Hide,
                'elementId' => 1,
                'elementType' => self::ENTRY,
            ]),
        ]);

        return $rule;
    }
}

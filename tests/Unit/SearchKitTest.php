<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\base\Plugin;
use craft\db\Migration;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\SearchKit;
use Tahadudhiya\SearchKit\services\Indexes;
use Tahadudhiya\SearchKit\services\Providers;
use Tahadudhiya\SearchKit\services\Search;
use Tahadudhiya\SearchKit\services\SearchableFields;

/**
 * Covers what Craft reads from the plugin itself: autoloading, metadata, and the components it
 * registers. Installing into a real Craft install is verified separately.
 */
class SearchKitTest extends TestCase
{
    private const PLUGIN_CLASS = 'Tahadudhiya\\SearchKit\\SearchKit';
    private const INSTALL_MIGRATION_CLASS = 'Tahadudhiya\\SearchKit\\migrations\\Install';

    public function testPluginClassAutoloads(): void
    {
        self::assertTrue(class_exists(SearchKit::class));
    }

    public function testPluginExtendsCraftPluginBaseClass(): void
    {
        // Asserted through a string so static analysis cannot narrow away what the autoloader does.
        self::assertTrue(is_subclass_of(self::PLUGIN_CLASS, Plugin::class));
    }

    public function testComposerMetadataMatchesPluginClass(): void
    {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertSame('craft-plugin', $composer['type']);
        self::assertSame(SearchKit::class, $composer['extra']['class']);
        self::assertSame('search-kit', $composer['extra']['handle']);
        self::assertSame('SearchKit', $composer['extra']['name']);
    }

    public function testSchemaVersionIsDeclared(): void
    {
        $schemaVersion = (new \ReflectionClass(SearchKit::class))
            ->getDefaultProperties()['schemaVersion'];

        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $schemaVersion);
    }

    public function testInstallMigrationAutoloadsAndIsAMigration(): void
    {
        self::assertTrue(is_subclass_of(self::INSTALL_MIGRATION_CLASS, Migration::class));
    }

    public function testServiceComponentsAreRegistered(): void
    {
        $components = SearchKit::config()['components'];

        self::assertSame(Indexes::class, $components['indexes']['class']);
        self::assertSame(Providers::class, $components['providers']['class']);
        self::assertSame(Search::class, $components['search']['class']);
        self::assertSame(SearchableFields::class, $components['searchableFields']['class']);
    }
}

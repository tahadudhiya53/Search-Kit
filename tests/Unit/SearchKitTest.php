<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\base\Plugin;
use craft\db\Migration;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\migrations\Install;
use Tahadudhiya\SearchKit\SearchKit;

/**
 * Covers the plugin foundation: that Composer autoloading resolves both classes, that the plugin
 * class is a Craft plugin declaring the metadata Craft reads, and that the install/uninstall
 * migration lifecycle is wired.
 *
 * These assert against the classes rather than a booted Craft app; installing into a real Craft
 * install is verified separately (`php craft plugin/install search-kit`).
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
        // Class names as strings, not ::class: these assert what the *autoloader* resolves at
        // runtime, which is the point of a foundation test. Referencing the classes directly would
        // let static analysis narrow both sides and report the assertion as always true.
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

    /**
     * Craft reads the return value of both, and a migration returning false is reported as a
     * failed install/uninstall.
     *
     * Constructed without `init()`, which resolves Yii's `db` application component and so needs a
     * booted app: while the migration has no schema, neither method touches the connection. Once
     * schema lands here this has to become an integration test against a real Craft install.
    */
    public function testInstallMigrationLifecycleSucceeds(): void
    {
        $migration = (new \ReflectionClass(Install::class))->newInstanceWithoutConstructor();

        self::assertTrue($migration->safeUp());
        self::assertTrue($migration->safeDown());
    }
}

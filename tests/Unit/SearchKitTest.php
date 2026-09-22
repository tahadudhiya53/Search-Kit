<?php

namespace Tahadudhiya\SearchKit\Tests\Unit;

use craft\base\Plugin;
use craft\db\Migration;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SearchKit\SearchKit;

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
        self::assertSame('Search Kit', $composer['extra']['name']);
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
     * Every registered service has to be reachable, or a caller finds out at runtime that the one
     * it wants was never given an accessor.
     */
    public function testEveryRegisteredComponentIsAResolvableServiceWithAnAccessor(): void
    {
        $plugin = new \ReflectionClass(SearchKit::class);

        foreach (SearchKit::config()['components'] as $name => $component) {
            $class = $component['class'];

            self::assertTrue(class_exists($class), "{$name} names a class that does not exist.");
            self::assertTrue(
                $plugin->hasMethod('get' . ucfirst($name)),
                "{$name} is registered but has no get" . ucfirst($name) . '() accessor.',
            );
            self::assertSame(
                $class,
                (string)$plugin->getMethod('get' . ucfirst($name))->getReturnType(),
                "get{$name}() does not return what {$name} is registered as.",
            );
        }
    }

    /**
     * The accessors and the registration are two lists of the same services, so neither may carry
     * a service the other does not.
     */
    public function testEveryServiceAccessorHasARegisteredComponent(): void
    {
        $registered = array_keys(SearchKit::config()['components']);
        $accessors = [];

        foreach ((new \ReflectionClass(SearchKit::class))->getMethods() as $method) {
            $type = (string)$method->getReturnType();

            if ($method->getDeclaringClass()->getName() !== SearchKit::class
                || !str_starts_with($method->getName(), 'get')
                || $method->getNumberOfParameters() > 0
                || !str_starts_with($type, 'Tahadudhiya\\SearchKit\\services\\')) {
                continue;
            }

            $accessors[] = lcfirst(substr($method->getName(), 3));
        }

        sort($registered);
        sort($accessors);

        self::assertSame($registered, $accessors);
    }
}

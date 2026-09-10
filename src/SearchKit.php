<?php

namespace Tahadudhiya\SearchKit;

use Craft;
use craft\base\Plugin;

/**
 * SearchKit — search management and intelligence for Craft CMS.
 *
 * This class is deliberately thin, and stays that way: search behaviour belongs in services and
 * components registered from `init()` as they are built, never here. Nothing in the foundation
 * schedules work, reads project config, or touches the database at construction time.
*/
class SearchKit extends Plugin
{
    /**
     * @inheritdoc Bump this whenever `migrations/Install.php` gains schema, so existing installs
     * are offered the matching update migration.
    */
    public string $schemaVersion = '0.1.0';

    /**
     * @inheritdoc
    */
    public function init(): void
    {
        parent::init();

        // Craft is only partly booted while plugins are being constructed, so anything that reads
        // project config, the database, or other plugins has to wait for this callback. Component
        // registration — services, CP nav, URL rules, permissions, Twig variables — lands inside
        // it as the plugin grows.
        Craft::$app->onInit(function() {
        });
    }
}

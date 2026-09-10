<?php

/**
 * Test bootstrap.
 *
 * Resolves the Composer autoloader whether the plugin is being tested standalone (its own
 * vendor/) or from inside a consuming Craft project (that project's vendor/, which is how the
 * path-repository development setup works).
*/

$autoloadCandidates = [
    __DIR__ . '/../../../vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;

        $yiiClass = dirname($autoload) . '/yiisoft/yii2/Yii.php';

        if (file_exists($yiiClass)) {
            require $yiiClass;
        }

        // Craft::t() falls back to plain strtr() placeholder substitution when Craft::$app is null
        // (see yii\BaseYii::t()), so loading just the class — no booted app — is enough for these
        // no-booted-app unit tests.
        $craftClass = dirname($autoload) . '/craftcms/cms/src/Craft.php';

        if (file_exists($craftClass)) {
            require $craftClass;
        }

        return;
    }
}

fwrite(STDERR, "Could not find a Composer autoloader. Run `composer install` in the consuming Craft project first.\n");
exit(1);

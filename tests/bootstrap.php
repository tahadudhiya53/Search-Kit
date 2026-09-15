<?php

// Resolves the autoloader whether the plugin is tested standalone or from a consuming Craft
// project, which is how the path-repository development setup works.

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

        // Craft::t() falls back to plain placeholder substitution when Craft::$app is null, so
        // loading the class alone is enough for unit tests that never boot an app.
        $craftClass = dirname($autoload) . '/craftcms/cms/src/Craft.php';

        if (file_exists($craftClass)) {
            require $craftClass;
        }

        return;
    }
}

fwrite(STDERR, "Could not find a Composer autoloader. Run `composer install` in the consuming Craft project first.\n");
exit(1);

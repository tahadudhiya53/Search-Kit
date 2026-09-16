<?php

// Boots the surrounding Craft project so integration tests run against a real app and database.
// Craft loads its own patched Yii and Craft classes, so nothing may be required before this.

$projectRoot = dirname(__DIR__, 3);

if (!file_exists("$projectRoot/bootstrap.php")) {
    fwrite(STDERR, "Integration tests need SearchKit to sit inside a Craft project. Run them from there.\n");
    exit(1);
}

require "$projectRoot/bootstrap.php";
require "$projectRoot/vendor/craftcms/cms/bootstrap/console.php";

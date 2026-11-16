<?php

// Static-analysis declarations only: never loaded by the runtime/autoloader.
// https://github.com/symfony/php-ext-deepclone/blob/main/deepclone.stub.php

const DEEPCLONE_HYDRATE_NO_LAZY_INIT = 2;

/** @param array<string, mixed> $vars */
function deepclone_hydrate(object|string $object_or_class, array $vars = [], int $flags = 0): object
{
}

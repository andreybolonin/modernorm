<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setRules(['@PSR12' => true])
    ->setFinder(Finder::create()
        ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/benchmarks', __DIR__.'/examples', __DIR__.'/tools'])
        ->append([__FILE__]));

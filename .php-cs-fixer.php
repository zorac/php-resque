<?php

use PhpCsFixer\Finder;
use PhpCsFixer\Config;

$finder = Finder::create()
    ->exclude('bin')
    ->exclude('demo')
    ->exclude('extras')
    ->exclude('src')
    ->exclude('test')
    ->in(__DIR__)
;

$config = new Config();

return $config->setRules([
    '@PSR12' => true,
])->setFinder($finder);

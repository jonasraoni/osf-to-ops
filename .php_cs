<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->name('_ide_helper')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$rules = include '.php_cs_rules';

$config = new PhpCsFixer\Config();
return $config->setRules($rules)
    ->setFinder($finder);

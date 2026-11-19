<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/migrations', __DIR__.'/public'])
    ->append([__DIR__.'/.php-cs-fixer.dist.php'])
    ->notPath('bundles.php')
    ->notPath('reference.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'phpdoc_to_comment' => false,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');

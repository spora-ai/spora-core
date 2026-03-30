<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0'                        => true,
        'declare_strict_types'              => true,
        'fully_qualified_strict_types'      => true,   // replace \FQCN usages with use imports
        'global_namespace_import'           => [       // import global PHP classes (RuntimeException etc.)
            'import_classes'    => true,
            'import_functions'  => false,
            'import_constants'  => false,
        ],
        'ordered_imports'                   => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                 => true,
        'single_import_per_statement'       => true,
    ])
    ->setFinder($finder);

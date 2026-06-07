<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP83Migration' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_namespace' => true,
        'no_extra_blank_lines' => true,
        'phpdoc_line_span' => [
            'property' => 'multi',
            'method' => 'multi',
            'const' => 'multi',
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache');

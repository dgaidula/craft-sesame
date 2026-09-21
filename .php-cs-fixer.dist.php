<?php

/**
 * PHP-CS-Fixer — self-contained config for the Sesame plugin.
 *
 * PSR-12 (4 spaces). Lives inside the plugin so it travels when the plugin is
 * extracted to its own repo. Run via: `composer cs-check` / `composer cs-fix`.
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'blank_line_after_opening_tag' => true,
    ])
    ->setFinder($finder);

<?php

$finder = PhpCsFixer\Finder::create()
    // Legacy src/ remains reference code until Phase 1 replaces each subsystem.
    // It is intentionally excluded here to avoid rewriting in-progress user work.
    ->in([__DIR__ . '/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
    ])
    ->setFinder($finder);

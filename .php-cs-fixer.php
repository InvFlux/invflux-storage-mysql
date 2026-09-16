<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
;

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRules([
        // ---------------------------------------------------------------------
        // Base standard: strict, clean, predictable
        // ---------------------------------------------------------------------
        '@Symfony' => true,

        // ---------------------------------------------------------------------
        // OVERRIDES to Symfony to better suit DTOT's expressive coding style
        // ---------------------------------------------------------------------

        // 1) Allow multi-line throws (DTO exception factories require this!)
        'single_line_throw' => false,

        // 2) Preserve developer's intent for multi-line method calls
        //    (allows nice formatting for TransformException::failed(...))
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],

        // 3) Prevent indentation weirdness on chained calls
        'method_chaining_indentation' => false,

        // 4) Allow multi-line whitespace before ; (we want clean multi-line arrays)
        'multiline_whitespace_before_semicolons' => false,

        // 5) Keep /** @psalm-suppress */ and other annotations intact
        'phpdoc_to_comment' => false,

        // 6) Keep nullable, union type spacing clean and readable
        'types_spaces' => ['space' => 'single'],

        // 7) Enforce strict comparisons ("===" and "!==")
        'strict_comparison' => true,

        // 8) Enforce strict parsing (“declare strict types”; optional but recommended)
        'declare_strict_types' => true,

        // ---------------------------------------------------------------------
        // Extra readability adjustments for DTO-heavy codebases
        // ---------------------------------------------------------------------

        // 9) Keep array syntax aligned when multiline
        'array_indentation' => true,

        // 10) Clean multi-line array formatting
        'array_syntax' => ['syntax' => 'short'],

        // 11) Align => arrows for readability (you already added this)
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align',
            ],
        ],

        // 12) Avoid collapsing control structures; improves readability
        'no_superfluous_elseif' => true,

        // 13) Keep the code clean but not overly aggressive
        'no_extra_blank_lines' => [
            'tokens' => [
                'extra',
                // leave out return, throw, use, etc. for readability
            ],
        ],

        // 14) Allow trailing commas in multiline function calls (important!)
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'parameters'],
        ],

        // ---------------------------------------------------------------------
        // Rules specifically useful for DTOT internals
        // ---------------------------------------------------------------------

        // 15) Clean and predictable lambda style for inline transforms
        'lambda_not_used_import' => true,

        // 16) Avoid risky rearranging that may change semantics
        'no_null_property_initialization'               => false,      // allow explicit "= null"
        'no_unneeded_final_method'                      => false,
        'phpdoc_trim_consecutive_blank_line_separation' => false,
    ])
    ->setFinder($finder);

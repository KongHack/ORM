<?php
/**
* Config Options can be found here: https://mlocati.github.io/php-cs-fixer-configurator
*/

$tmp  = explode(DIRECTORY_SEPARATOR, __DIR__);
$hunt = array_pop($tmp);
$dir  = __DIR__.DIRECTORY_SEPARATOR;

$finder = new PhpCsFixer\Finder();
$finder->in($dir.'src')
    ->exclude($dir.'src/Generated*')
    ->exclude($dir.'src/Generated')
    ->exclude('*src/Generated*')
    ->exclude('*Generated*')
    ->exclude('src/Generated*')
    ->exclude('Generated')
    ->exclude('src/Generated')
    ->exclude('*Generated*')
    ->exclude($dir.'src/Generated/*')
    ->exclude($dir.'src/Generated/')
    ->exclude('*src/Generated/*')
    ->exclude('*Generated/*')
    ->exclude('src/Generated/*')
    ->exclude('Generated/')
    ->exclude('src/Generated/')
    ->exclude('*Generated*');

$phpDocSeparation = ['groups' => [
    ['deprecated', 'link', 'see', 'since'],
    ['author', 'copyright', 'license'],
    ['category', 'package', 'subpackage'],
    ['property', 'property-read', 'property-write'],
]];

$routerPieces = [
    'autoWrapper',
    'pattern',
    'name',
    'session',
    'preArgs',
    'postArgs',
    'title',
    'meta',
];
sort($routerPieces);
$tmp = [];
foreach($routerPieces as $piece) {
    $tmp[] = 'router-'.$piece;
}
$phpDocSeparation['groups'][] = $tmp;
for($i=1;$i<10;$i++) {
    $tmp = [];
    foreach($routerPieces as $piece) {
        $tmp[] = 'router-'.$i.'-'.$piece;
    }
    $phpDocSeparation['groups'][] = $tmp;
}

$config = new PHPCsFixer\Config();
$config->setRiskyAllowed(true);
$config->setRules([
    '@PhpCsFixer'                           => true,
    '@PHP80Migration'                       => true,
    '@PHP81Migration'                       => true,
    '@PHP82Migration'                       => true,
    '@PHP83Migration'                       => true,
    '@PHP84Migration'                       => true,
    '@Symfony'                              => true,
    '@Symfony:risky'                        => false,
    'array_syntax'                          => ['syntax' => 'short'],
    'combine_consecutive_unsets'            => false,
    // one should use PHPUnit methods to set up expected exception instead of annotations
    'general_phpdoc_annotation_remove'      => ['annotations' => [
        'expectedException',
        'expectedExceptionMessage',
        'expectedExceptionMessageRegExp',
        'coversNothing'
    ]],
    'heredoc_to_nowdoc'                     => true,
    'list_syntax'                           => ['syntax' => 'long'],
    'no_extra_blank_lines'                  => ['tokens' => [
        'break',
        'continue',
        'extra',
        'return',
        'throw',
        'use',
        'parenthesis_brace_block',
        'square_brace_block',
        'curly_brace_block'
    ]],
    'echo_tag_syntax'                       => ['format' => 'short'],
    'no_unreachable_default_argument_value' => true,
    'no_useless_else'                       => true,
    'no_useless_return'                     => true,
    'ordered_class_elements'                => ['order' => [
        'use_trait',
        'constant_public',
        'constant_protected',
        'constant_private',
        'property_public',
        'property_protected',
        'property_private',
        'construct',
        'destruct',
        'magic',
        'phpunit',
        'method_public',
        'method_protected',
        'method_private'
    ]],
    'php_unit_strict'                       => true,
    'phpdoc_add_missing_param_annotation'   => true,
    'phpdoc_order'                          => true,
    'semicolon_after_instruction'           => true,
    'strict_comparison'                     => false,
    'strict_param'                          => false,
    'phpdoc_no_empty_return'                => false,
    'phpdoc_no_package'                     => true,     // PHPCS doesn't like it either
    'blank_line_after_opening_tag'          => false,
    'phpdoc_align'                          => [
        'align' => 'vertical',
        'tags'  => [
            'param',
            'property',
            'property-read',
            'property-write',
            'return',
            'throws',
            'type',
            'var',
            'method',
        ]
    ],
    'modernize_types_casting'               => false,
    'doctrine_annotation_braces'            => false,   // "Covers nothing" BS
    'doctrine_annotation_indentation'       => false,   // "Covers nothing" BS
    'doctrine_annotation_spaces'            => false,   // "Covers nothing" BS
    'php_unit_test_class_requires_covers'   => false,   // "Covers nothing" BS
    'binary_operator_spaces'                => false,   // Used to align array keys, but screws up = and .=
    'blank_lines_before_namespace'          => ['min_line_breaks' => 1, 'max_line_breaks' => 1],
    // New Things
    'array_indentation'                      => true,
    'align_multiline_comment'                => true,
    'cast_spaces'                            => ['space'=>'single'],
    'blank_line_before_statement'            => ['statements'=> ['break','continue','declare','return','throw','try']],
    'type_declaration_spaces'                => ['elements' => ['function']],
    'single_line_empty_body'                 => false,
    'combine_consecutive_issets'             => true,
    'compact_nullable_type_declaration'      => true,
    'indentation_type'                       => true,
    'multiline_comment_opening_closing'      => true,
    'method_chaining_indentation'            => true,
    'no_superfluous_elseif'                  => true,
    'no_alternative_syntax'                  => true,
    'multiline_whitespace_before_semicolons' => ['strategy'=>'no_multi_line'],
    'fully_qualified_strict_types'           => true,
    'native_function_invocation'             => ['scope'=>'namespaced','include'=>['@internal']],
    'no_unused_imports'                      => true,
    'no_superfluous_phpdoc_tags'             => false, // Conflicts with core practices
    'single_line_throw'                      => false, // Why in the hell?
    'no_trailing_comma_in_singleline'        => false, // We want ALL arrays broken down, regardless if it's one line or not
    'no_alias_language_construct_call'       => false, // No idea, but it hits all Core stuff, like Errors, Common, Session, etc.
    'declare_strict_types'                   => false, // Ungodly dangerous
    'heredoc_indentation'                    => false, // We take advantage of the full left align for things like embedded keys
    'php_unit_internal_class'                => false, // This literally adds @internal to any classes that have "Test" in the name, like LMSTest...
    'phpdoc_to_comment'                      => false, // Kills @var declarations and
    'phpdoc_separation'                      => $phpDocSeparation,
    'standardize_increment'                  => false, // This pisses PHPStan off for some reason
    'global_namespace_import'                => [
        'import_classes'   => true,
        'import_constants' => null,
        'import_functions' => null,
    ],
    'ordered_imports'                        => [
        'sort_algorithm' => 'alpha',
        'imports_order' => ['const', 'class', 'function'],
    ],
    'string_implicit_backslashes'            => [
        'heredoc'       => 'ignore',
        'double_quoted' => 'ignore',
        'single_quoted' => 'ignore',
    ],
    'trailing_comma_in_multiline'            => [
        'after_heredoc' => true,
        'elements'      => ['arrays', 'match'],
    ],
]);
$config->setFinder($finder);

// Pathing for caching!
$tmp   = explode(DIRECTORY_SEPARATOR, __DIR__);
$index = array_search($hunt, $tmp);

$instance = $tmp[$index - 2] ?? '';
$worker   = $tmp[$index - 3] ?? '';

$path = '/tmp/gcworld/'.$hunt.'-'.$worker.'-'.$instance.'/';

if(!is_dir('/tmp/gcworld')) {
    mkdir('/tmp/gcworld');
}
if(!is_dir($path)) {
    mkdir($path);
}

$config->setUsingCache(true);
$config->setCacheFile($path.'.php-cs-fixer.cache');

return $config;


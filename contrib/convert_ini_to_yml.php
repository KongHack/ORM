<?php

require('../../../autoload.php');


$file = 'GCWorld_ORM.ini';

if(!file_exists($file)) {
    die('File Not Found:'.$file.PHP_EOL.'Please change the file location to the location of your ini file');
}


$config   = parse_ini_file($file, true);
$redirect = '';

if (isset($config['config_path'])) {
    $redirect = $file;
    $file     = $config['config_path'];
    $config   = parse_ini_file($file, true);
}

$overrides    = [];
$return_types = [];
$type_hints   = [];
foreach ($config as $k => $v) {
    if (substr($k, 0, 9) == 'override:') {
        $overrides[substr($k, 9)] = $v;
        unset($config[$k]);
    } elseif (substr($k, 0, 11) == 'type_hints:') {
        $type_hints[substr($k, 11)] = $v;
        unset($config[$k]);
    }
}

// New Config Array
$config['tables'] = [];
foreach ($overrides as $table => $override) {
    if (!array_key_exists($table, $config['tables'])) {
        $config['tables'][$table] = [];
    }
    $config['tables'][$table]['overrides'] = $override;
}
foreach ($return_types as $table => $return_type) {
    if (!array_key_exists($table, $config['tables'])) {
        $config['tables'][$table] = [];
    }
    $config['tables'][$table]['return_types'] = $return_type;
}
foreach ($type_hints as $table => $type_hint) {
    if (!array_key_exists($table, $config['tables'])) {
        $config['tables'][$table] = [];
    }
    $config['tables'][$table]['type_hints'] = $type_hint;
}
if (array_key_exists('audit_ignore', $config)) {
    foreach ($config['audit_ignore'] as $table => $ignores) {
        if (!array_key_exists($table, $config['tables'])) {
            $config['tables'][$table] = [];
        }
        $config['tables'][$table]['audit_ignore'] = $ignores;
    }
}

// Because unsetting isn't working the first time around...
foreach($config as $k => $v) {
    if(strpos($k,'override:')!==false) {
        unset($config[$k]);
    }
    if(strpos($k,'return_types:')!==false) {   // Trumped for type hints
        unset($config[$k]);
    }
    if(strpos($k,'type_hints:')!==false) {
        unset($config[$k]);
    }
}
unset($config['audit_ignore']);

foreach($config['options'] as $k => $v) {
    if($v === 1 || $v === '1') {
        $config['options'][$k] = true;
    }
    if($v === 0 || $v === '0' || $v === '') {
        $config['options'][$k] = false;
    }
}

$example = $config['tables']['EXAMPLE_TABLE'];
unset($config['tables']['EXAMPLE_TABLE']);
ksort($config['tables']);
$config['tables']['EXAMPLE_TABLE'] = $example;


$newFile = str_replace('.ini', '.yml', $file);
file_put_contents($newFile, \Symfony\Component\Yaml\Yaml::dump($config, 5));

if ($redirect != '') {
    $newRedirect = str_replace('.ini', '.yml', $redirect);
    file_put_contents($newRedirect, \Symfony\Component\Yaml\Yaml::dump([
        'config_path' => $newFile
    ], 4));
}

return $config;

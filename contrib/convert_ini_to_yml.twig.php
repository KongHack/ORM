<?php




$file = rtrim(dirname(__FILE__), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR;
$file .= 'config'.DIRECTORY_SEPARATOR.'config.ini';

if(!file_exists($file)) {
    die('Please change the file location to the location of your ini file');
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
foreach ($config as $k => $v) {
    if (substr($k, 0, 9) == 'override:') {
        $overrides[substr($k, 9)] = $v;
        unset($config[$k]);
    } elseif (substr($k, 0, 13) == 'return_types:') {
        $return_types[substr($k, 13)] = $v;
        unset($config[$k]);
    }
}

// New Config Array
$config['tables'] = [];
foreach ($overrides as $table => $override) {
    if (!array_key_exists($table, $config['tables'])) {
        $config['tables'][$table] = [];
    }
    $config['tables'][$table]['override'] = $override;
}
foreach ($return_types as $table => $return_type) {
    if (!array_key_exists($table, $config['tables'])) {
        $config['tables'][$table] = [];
    }
    $config['tables'][$table]['return_types'] = $return_type;
}
if (array_key_exists('audit_ignore', $config)) {
    foreach ($config['audit_ignore'] as $table => $ignores) {
        if (!array_key_exists($table, $config['tables'])) {
            $config['tables'][$table] = [];
        }
        $config['tables'][$table]['audit_ignore'] = $ignores;
    }
}
unset($config['audit_ignore']);

$newFile = str_replace($file, '.ini', '.yml');
file_put_contents($newFile, \Symfony\Component\Yaml\Yaml::dump($config, 5));

if ($redirect != '') {
    $newRedirect = str_replace($redirect, '.ini', '.yml');
    file_put_contents($newRedirect, \Symfony\Component\Yaml\Yaml::dump([
        'config_path' => $newFile
    ], 4));
}

return $config;

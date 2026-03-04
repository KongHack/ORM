<?php
$tmp  = explode(DIRECTORY_SEPARATOR, __DIR__);
$hunt = end($tmp);
reset($tmp);
$index = array_search($hunt, $tmp);

$instance = $tmp[$index - 2] ?? 'y';
$worker   = $tmp[$index - 3] ?? 'x';

$binpath = __DIR__.'/vendor/bin/phpstan';
$output  = shell_exec($binpath.' --version');
$output  = trim($output??'');
$tmp     = explode(' ',$output);
$version = array_pop($tmp);
$version = trim($version);
$version = preg_replace("/[^a-zA-Z0-9.]/", "", $version);

$path = '/tmp/gcphpstan/'.$hunt.'-'.$worker.'-'.$instance.'-'.$version.'/';

echo PHP_EOL;
echo 'CONFIGURED TEMP PATH: ',$path,PHP_EOL;
echo PHP_EOL;

if(!is_dir('/tmp/gcphpstan')) {
    mkdir('/tmp/gcphpstan');
}
if(!is_dir($path)) {
    mkdir($path);
} else {
    echo '!!! WOO, path already exists !!! We might be in the clear here !!! ', PHP_EOL;
}

$dirs = array_filter(glob('/tmp/gcphpstan/*'), 'is_dir');
print_r($dirs);


// Neon files REQUIRE actual tabs...
$contents  = 'parameters:'.PHP_EOL;
$contents .= "\t".'tmpDir: '.$path.PHP_EOL;
$contents .= PHP_EOL;

file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'phpstan.neon.dist', $contents);

<?php
namespace GCWorld\ORM;

use Composer\Script\Event;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ComposerInstaller
 * @package GCWorld\ORM
 */
class ComposerInstaller
{
    const CONFIG_FILE_NAME = 'GCWorld_ORM.yml';

    /**
     * @param \Composer\Script\Event $event
     * @return bool
     */
    public static function setupConfig(Event $event)
    {
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        $myDir     = dirname(__FILE__);

        // Determine if ORM yml already exists.
        $ymlPath = realpath($vendorDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config').DIRECTORY_SEPARATOR;

        if (!is_dir($ymlPath)) {
            @mkdir($ymlPath);
            if (!is_dir($ymlPath)) {
                echo 'WARNING:: Cannot create config folder in application root:: '.$ymlPath;

                return false;   // Silently Fail.
            }
        }

        if (!file_exists($ymlPath.self::CONFIG_FILE_NAME)) {
            $example = file_get_contents($myDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'config.example.yml');
            file_put_contents($ymlPath.self::CONFIG_FILE_NAME, $example);
        }

        $tmpYml = explode(DIRECTORY_SEPARATOR, $ymlPath);
        $tmpMy  = explode(DIRECTORY_SEPARATOR, $myDir);
        $loops  = max(count($tmpMy),count($tmpYml));

        array_pop($tmpYml); // Remove the trailing slash

        for($i=0;$i<$loops;++$i) {
            if(!isset($tmpYml[$i]) || !isset($tmpMy[$i])) {
                break;
            }
            if($tmpYml[$i] === $tmpMy[$i]) {
                unset($tmpYml[$i]);
                unset($tmpMy[$i]);
            }
        }

        $relPath  = str_repeat('..'.DIRECTORY_SEPARATOR,count($tmpMy));
        $relPath .= implode(DIRECTORY_SEPARATOR, $tmpYml);
        $ymlPath  = $relPath.DIRECTORY_SEPARATOR.self::CONFIG_FILE_NAME;

        $tmp = ['config_path' => $ymlPath];

        file_put_contents($myDir.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'config.yml', Yaml::dump($tmp,4));

        return true;
    }
}

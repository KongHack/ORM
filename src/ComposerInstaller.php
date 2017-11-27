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
    /**
     * @param \Composer\Script\Event $event
     * @return bool
     */
    public static function setupConfig(Event $event)
    {
        $ds        = DIRECTORY_SEPARATOR;
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        $myDir     = dirname(__FILE__);

        // Determine if ORM yml already exists.
        $ymlPath = realpath($vendorDir.$ds.'..'.$ds.'config').$ds;

        if (!is_dir($ymlPath)) {
            @mkdir($ymlPath);
            if (!is_dir($ymlPath)) {
                echo 'WARNING:: Cannot create config folder in application root:: '.$ymlPath;

                return false;   // Silently Fail.
            }
        }

        if (!file_exists($ymlPath.'GCWorld_ORM.yml')) {
            $example = file_get_contents($myDir.$ds.'..'.$ds.'config'.$ds.'config.example.yml');
            file_put_contents($ymlPath.'GCWorld_ORM.yml', $example);
        }

        file_put_contents($myDir.$ds.'..'.$ds.'config'.$ds.'config.yml', Yaml::dump([
            'config_path' => $ymlPath.'GCWorld_ORM.yml'
        ],4));
        return true;
    }
}

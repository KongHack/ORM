<?php
namespace GCWorld\ORM;

use Composer\Script\Event;

class ComposerInstaller
{
    static function setupConfig(Event $event)
    {
        $ds = DIRECTORY_SEPARATOR;
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        $myDir = dirname(__FILE__);

        // Determine if ORM ini already exists.
        $iniPath = realpath($vendorDir.$ds.'..'.$ds.'config').$ds;
        if (file_exists($iniPath.'GCWorld_ORM.ini')) {
            return true;    // Already Exists.
        }
        if (!is_dir($iniPath)) {
            @mkdir($iniPath);
            if (!is_dir($iniPath)) {
                echo 'WARNING:: Cannot create config folder in application root:: '.$iniPath;
                return false;   // Silently Fail.
            }
        }
        $example = file_get_contents($myDir.$ds.'..'.$ds.'config'.$ds.'config.example.ini');
        file_put_contents($iniPath.'GCWorld_ORM.ini', $example);
        file_put_contents($myDir.$ds.'..'.$ds.'config'.$ds.'config.ini', 'config_path='.$iniPath.'GCWorld_ORM.ini');
        return true;
    }
}

<?php

namespace GCWorld\ORM\Tests;

use GCWorld\ORM\Config;
use GCWorld\ORM\Audit; // Used as a default value in Config
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class ConfigTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $vfsPath; // vfs://root/

    // Path to the real config directory for the package where config.yml (pointer file) will be written
    private string $realPackageConfigDir;
    private string $realPackagePointerConfigYmlPath; // e.g., /actual/path/to/package/config/config.yml

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->vfsPath = vfsStream::url('root');

        // Determine real path for the package's config directory
        $configClassReflector = new \ReflectionClass(Config::class);
        $realPackageSrcDir = dirname($configClassReflector->getFileName());
        $this->realPackageConfigDir = dirname($realPackageSrcDir) . DIRECTORY_SEPARATOR . 'config';
        $this->realPackagePointerConfigYmlPath = $this->realPackageConfigDir . DIRECTORY_SEPARATOR . 'config.yml';

        // Ensure the real package config directory exists for the pointer file
        if (!is_dir($this->realPackageConfigDir)) {
            mkdir($this->realPackageConfigDir, 0777, true);
        }

        // Clean up any pointer file from previous runs
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }
    }

    protected function tearDown(): void
    {
        // Clean up the real pointer config file after each test
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }
        // Clean up directory if empty
        if (is_dir($this->realPackageConfigDir) && count(scandir($this->realPackageConfigDir)) <= 2) {
            rmdir($this->realPackageConfigDir);
        }
    }

    /**
     * Helper to create the real config.yml pointer file.
     * This file tells Config::__construct where to find the actual user config (in VFS).
     * @param string $vfsUserConfigPath Relative path from realPackageSrcDir to the VFS user config file.
     */
    private function createRealPackagePointerConfig(string $vfsUserConfigPath): void
    {
        $pointerConfig = ['config_path' => $vfsUserConfigPath];
        file_put_contents($this->realPackagePointerConfigYmlPath, Yaml::dump($pointerConfig));
    }

    private function getBasicValidUserConfigData(): array
    {
        return [
            'version' => Config::VERSION,
            'general' => [
                'common' => 'TestCommonClass',
                'user'   => 'TestUserClass',
                'audit_handler' => Audit::class, // Default or specific
            ],
            'tables' => [
                'test_table' => ['fields' => ['id' => ['type' => 'int']]]
            ],
            'descriptions' => [ // Add default descriptions
                'enabled'    => false,
                'desc_dir'   => null,
                'desc_trait' => null,
            ],
        ];
    }

    public function testConstructorLoadsUserConfigSuccessfully()
    {
        // User config file in VFS
        $userConfigVfsPath = $this->vfsPath . '/user_actual_config.yml';
        $userConfigData = $this->getBasicValidUserConfigData();
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        // Pointer config (real FS) points to VFS user config
        // Path must be relative from Config.php's dir (src/) to user_actual_config.yml in VFS
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()), // real src dir
            $userConfigVfsPath                                           // vfs user config file
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $config = new Config();
        $loadedConfig = $config->getConfig();

        $this->assertEquals($userConfigData['general']['common'], $loadedConfig['general']['common']);
        $this->assertEquals($userConfigVfsPath, $config->getConfigFilePath());

        // Check if cache file was created in VFS
        $cacheFileVfsPath = str_replace('.yml', '.php', $userConfigVfsPath);
        $this->assertFileExists($cacheFileVfsPath);
    }

    public function testConstructorThrowsExceptionIfPointerFileMissing()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ORM Config File Not Found');
        // Do not create $this->realPackagePointerConfigYmlPath
        new Config();
    }

    public function testConstructorThrowsExceptionIfUserConfigViaPathIsMissing()
    {
        $userConfigVfsPath = $this->vfsPath . '/non_existent_user_config.yml';
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $this->expectException(\Exception::class);
        // Symfony Yaml component throws its own error first if file not found
        // $this->expectExceptionMessage('ORM Config File Not Found');
        new Config();
    }
    
    public function testConstructorThrowsExceptionIfUserConfigIsEmpty()
    {
        $userConfigVfsPath = $this->vfsPath . '/empty_user_config.yml';
        file_put_contents($userConfigVfsPath, ''); // Empty file

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ORM Config Empty');
        new Config();
    }


    public function testConstructorThrowsExceptionForInvalidYamlInUserConfig()
    {
        $userConfigVfsPath = $this->vfsPath . '/invalid_yaml.yml';
        file_put_contents($userConfigVfsPath, "general: [common: Test\nuser: Test"); // Invalid YAML

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $this->expectException(ParseException::class);
        new Config();
    }

    public function testConstructorThrowsExceptionForMissingGeneralSection()
    {
        $userConfigVfsPath = $this->vfsPath . '/missing_general.yml';
        $badConfigData = ['version' => Config::VERSION, 'tables' => []]; // No 'general'
        file_put_contents($userConfigVfsPath, Yaml::dump($badConfigData));
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Missing entire General section');
        new Config();
    }


    public function testLoadsFromCacheIfCacheIsNewer()
    {
        $userConfigVfsPath = $this->vfsPath . '/user_config_for_cache_test.yml';
        $userConfigData = $this->getBasicValidUserConfigData();
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        // Create a cache file with slightly different data and newer timestamp
        $cacheFileVfsPath = str_replace('.yml', '.php', $userConfigVfsPath);
        $cachedConfigData = $userConfigData;
        $cachedConfigData['general']['common'] = 'CachedTestCommonClass';
        $phpCacheContent = '<?php return ' . var_export($cachedConfigData, true) . ';';
        file_put_contents($cacheFileVfsPath, $phpCacheContent);

        // Touch user config to be older, cache to be newer
        touch($userConfigVfsPath, time() - 3600); // 1 hour ago
        touch($cacheFileVfsPath, time());       // Now

        $config = new Config();
        $loadedConfig = $config->getConfig();
        $this->assertEquals('CachedTestCommonClass', $loadedConfig['general']['common']);
    }
    
    public function testTrustCacheBehavior()
    {
        $userConfigVfsPath = $this->vfsPath . '/user_config_for_trust_cache.yml';
        $userConfigData = $this->getBasicValidUserConfigData();
        $userConfigData['general']['trust_cache'] = true; // Enable trust_cache
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        // Create a cache file that is OLDER but should be trusted
        $cacheFileVfsPath = str_replace('.yml', '.php', $userConfigVfsPath);
        $cachedConfigData = $userConfigData; // Use same data, but could be different
        $cachedConfigData['general']['common'] = 'TrustedCachedCommon';
        $phpCacheContent = '<?php return ' . var_export($cachedConfigData, true) . ';';
        file_put_contents($cacheFileVfsPath, $phpCacheContent);

        touch($userConfigVfsPath, time());       // Source YAML is newer
        touch($cacheFileVfsPath, time() - 3600); // Cache is older

        $config = new Config();
        $loadedConfig = $config->getConfig();
        // Assert that it loaded from cache even though cache was older
        $this->assertEquals('TrustedCachedCommon', $loadedConfig['general']['common']);
        // Also, no new cache should be written (filemtime of cache should remain old)
        $this->assertEquals(time() - 3600, filemtime($cacheFileVfsPath));
    }


    public function testConfigUpgrade()
    {
        $userConfigVfsPath = $this->vfsPath . '/user_config_v3.yml';
        $oldConfigData = [
            'version' => 3, // Old version
            'general' => ['common' => 'OldCommon', 'user' => 'OldUser'],
            'tables' => [
                'my_table' => [
                    'overrides' => ['field1' => 'private'],
                    'audit_ignore_fields' => ['field2'],
                    'type_hints' => ['field1' => 'string']
                ]
            ],
            'sort' => true // Important for file to be re-written
        ];
        file_put_contents($userConfigVfsPath, Yaml::dump($oldConfigData));
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $config = new Config();
        $loadedConfig = $config->getConfig();

        $this->assertEquals(Config::VERSION, $loadedConfig['version']); // Should be upgraded
        $this->assertArrayHasKey('fields', $loadedConfig['tables']['my_table']);
        $this->assertEquals('private', $loadedConfig['tables']['my_table']['fields']['field1']['visibility']);
        $this->assertTrue($loadedConfig['tables']['my_table']['fields']['field2']['audit_ignore']);
        $this->assertEquals('string', $loadedConfig['tables']['my_table']['fields']['field1']['type_hint']);
        
        // Check if the VFS user config file was updated
        $updatedVfsContent = Yaml::parseFile($userConfigVfsPath);
        $this->assertEquals(Config::VERSION, $updatedVfsContent['version']);
    }

    public function testTableDirLoading()
    {
        // Main user config in VFS
        $userConfigVfsPath = $this->vfsPath . '/user_config_with_tabledir.yml';
        $userConfigData = $this->getBasicValidUserConfigData();
        $userConfigData['table_dir'] = 'tables_from_dir'; // Relative to user_config_with_tabledir.yml parent
        unset($userConfigData['tables']); // Remove initial tables to ensure they come from table_dir
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        // Create table_dir in VFS relative to $userConfigVfsPath
        $tableDirVfsPath = dirname($userConfigVfsPath) . DIRECTORY_SEPARATOR . 'tables_from_dir';
        mkdir($tableDirVfsPath);
        $table1Data = ['fields' => ['col1' => ['type' => 'varchar']]];
        file_put_contents($tableDirVfsPath . '/table1.yml', Yaml::dump($table1Data));
        $table2Data = ['fields' => ['data' => ['type' => 'text']]];
        file_put_contents($tableDirVfsPath . '/table2.yml', Yaml::dump($table2Data));
        
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);

        $config = new Config();
        $loadedConfig = $config->getConfig();

        $this->assertArrayHasKey('table1', $loadedConfig['tables']);
        $this->assertEquals($table1Data['fields']['col1']['type'], $loadedConfig['tables']['table1']['fields']['col1']['type']);
        $this->assertArrayHasKey('table2', $loadedConfig['tables']);
        $this->assertArrayNotHasKey('table_dir', $loadedConfig, "'table_dir' should be unset after loading.");
    }
    
    public function testTableDirNotFoundThrowsException()
    {
        $userConfigVfsPath = $this->vfsPath . '/user_config_bad_tabledir.yml';
        $userConfigData = $this->getBasicValidUserConfigData();
        $userConfigData['table_dir'] = 'non_existent_table_dir';
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Table Dir is defined but cannot be found:/');
        new Config();
    }


    public static function testGetDefaultFieldConfig()
    {
        $defaults = Config::getDefaultFieldConfig();
        self::assertIsArray($defaults);
        self::assertArrayHasKey('visibility', $defaults);
        self::assertEquals('public', $defaults['visibility']);
    }

    // Helper to calculate relative path (crucial for pointer config)
    private function calculateRelativePath(string $from, string $to): string
    {
        $from = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $from);
        $to = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $to);
        $fromArr = explode(DIRECTORY_SEPARATOR, rtrim($from, DIRECTORY_SEPARATOR));
        $toArr = explode(DIRECTORY_SEPARATOR, rtrim($to, DIRECTORY_SEPARATOR));

        while (count($fromArr) > 0 && count($toArr) > 0 && $fromArr[0] == $toArr[0]) {
            array_shift($fromArr);
            array_shift($toArr);
        }
        return str_repeat('..' . DIRECTORY_SEPARATOR, count($fromArr)) . implode(DIRECTORY_SEPARATOR, $toArr);
    }
}

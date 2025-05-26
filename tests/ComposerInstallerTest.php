<?php

namespace GCWorld\ORM\Tests;

use GCWorld\ORM\ComposerInstaller;
use PHPUnit\Framework\TestCase;
use Composer\Script\Event;
use Composer\Composer;
use Composer\Config as ComposerConfig; // Alias to avoid conflict with GCWorld\ORM\Config
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Yaml\Yaml;

class ComposerInstallerTest extends TestCase
{
    private vfsStreamDirectory $root; // VFS root directory
    private string $projectRootPath;   // vfs://root
    private string $vendorDir;         // vfs://root/vendor
    private string $packageVfsRootPath; // vfs://root/vendor/gcworld/orm
    private string $packageVfsConfigPath; // vfs://root/vendor/gcworld/orm/config (for VFS master example.yml)
    private string $projectVfsConfigPath; // vfs://root/config (target for user's GCWorld_ORM.yml)

    // Paths related to the *real* location of the ComposerInstaller.php and its surrounding package files
    private string $realPackageSourceDir;           // Real path to this package's src/ where ComposerInstaller.php lives
    private string $realPackageConfigDir;           // Real path to this package's config/
    private string $realPackageInternalConfigYmlPath; // Real path to package's output config.yml (stores relative path)
    private string $realPackageExampleYmlPath;      // Real path to package's source config.example.yml

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->projectRootPath = vfsStream::url('root');

        // VFS paths (where the "project" and "vendor" dirs will exist virtually)
        $this->vendorDir = $this->projectRootPath . '/vendor';
        $this->packageVfsRootPath = $this->vendorDir . '/gcworld/orm';
        $this->packageVfsConfigPath = $this->packageVfsRootPath . '/config';
        $this->projectVfsConfigPath = $this->projectRootPath . '/config'; // Target for user's GCWorld_ORM.yml

        // Create VFS directory for the package's config to store the example.yml (master copy)
        mkdir($this->packageVfsConfigPath, 0777, true);
        $exampleContent = Yaml::dump(['example_key' => 'example_value_vfs']);
        file_put_contents($this->packageVfsConfigPath . '/config.example.yml', $exampleContent);

        // Determine real paths for the currently running package
        $this->realPackageSourceDir = dirname((new \ReflectionClass(ComposerInstaller::class))->getFileName());
        $this->realPackageConfigDir = dirname($this->realPackageSourceDir) . DIRECTORY_SEPARATOR . 'config';
        $this->realPackageInternalConfigYmlPath = $this->realPackageConfigDir . DIRECTORY_SEPARATOR . 'config.yml';
        $this->realPackageExampleYmlPath = $this->realPackageConfigDir . DIRECTORY_SEPARATOR . 'config.example.yml';

        // Clean up any real files from previous test runs before each test
        $this->cleanupRealFiles();
        // Recreate real package config dir for tests that need to write/read real files
        if (!is_dir($this->realPackageConfigDir)) {
            mkdir($this->realPackageConfigDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupRealFiles();
    }

    private function cleanupRealFiles(): void
    {
        if (file_exists($this->realPackageInternalConfigYmlPath)) {
            unlink($this->realPackageInternalConfigYmlPath);
        }
        if (file_exists($this->realPackageExampleYmlPath)) {
            unlink($this->realPackageExampleYmlPath);
        }
        if (is_dir($this->realPackageConfigDir)) {
            // Check if dir is empty before trying to remove
            $scan = scandir($this->realPackageConfigDir);
            if ($scan && count($scan) <= 2) { // Only '.' and '..'
                rmdir($this->realPackageConfigDir);
            }
        }
    }

    protected function mockEvent(): Event
    {
        $event = $this->getMockBuilder(Event::class)->disableOriginalConstructor()->getMock();
        $composer = $this->getMockBuilder(Composer::class)->disableOriginalConstructor()->getMock();
        $composerConfig = $this->getMockBuilder(ComposerConfig::class)->disableOriginalConstructor()->getMock();

        // Point 'vendor-dir' to VFS vendor directory
        $composerConfig->method('get')->with('vendor-dir')->willReturn($this->vendorDir);
        $composer->method('getConfig')->willReturn($composerConfig);
        $event->method('getComposer')->willReturn($composer);
        return $event;
    }

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

    public function testSetupConfigCreatesDirAndCopiesConfigWhenNotExist()
    {
        $event = $this->mockEvent();
        $this->assertDirectoryDoesNotExist($this->projectVfsConfigPath); // VFS project config dir

        // Copy VFS master example to where ComposerInstaller will read it (real path)
        copy($this->packageVfsConfigPath . '/config.example.yml', $this->realPackageExampleYmlPath);

        $result = ComposerInstaller::setupConfig($event);
        $this->assertTrue($result);

        // Assert VFS project config directory and file are created
        $this->assertDirectoryExists($this->projectVfsConfigPath);
        $targetUserOrmYml = $this->projectVfsConfigPath . '/' . ComposerInstaller::CONFIG_FILE_NAME;
        $this->assertFileExists($targetUserOrmYml);
        $this->assertEquals(
            file_get_contents($this->packageVfsConfigPath . '/config.example.yml'), // Compare with VFS master
            file_get_contents($targetUserOrmYml)
        );

        // Assert package's internal config.yml (on real filesystem)
        $this->assertFileExists($this->realPackageInternalConfigYmlPath);
        $internalConfig = Yaml::parseFile($this->realPackageInternalConfigYmlPath);
        $expectedRelativePath = $this->calculateRelativePath($this->realPackageSourceDir, $this->projectVfsConfigPath);
        $this->assertEquals(
            rtrim($expectedRelativePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ComposerInstaller::CONFIG_FILE_NAME,
            $internalConfig['config_path']
        );
    }

    public function testSetupConfigCopiesConfigWhenDirExistsButFileNotExist()
    {
        mkdir($this->projectVfsConfigPath, 0777, true); // Create VFS project config dir
        $this->assertDirectoryExists($this->projectVfsConfigPath);
        $targetUserOrmYml = $this->projectVfsConfigPath . '/' . ComposerInstaller::CONFIG_FILE_NAME;
        $this->assertFileDoesNotExist($targetUserOrmYml);

        $event = $this->mockEvent();
        copy($this->packageVfsConfigPath . '/config.example.yml', $this->realPackageExampleYmlPath);

        $result = ComposerInstaller::setupConfig($event);
        $this->assertTrue($result);

        $this->assertFileExists($targetUserOrmYml); // VFS
        $this->assertEquals(
            file_get_contents($this->packageVfsConfigPath . '/config.example.yml'),
            file_get_contents($targetUserOrmYml)
        );

        $this->assertFileExists($this->realPackageInternalConfigYmlPath); // Real FS
        $internalConfig = Yaml::parseFile($this->realPackageInternalConfigYmlPath);
        $expectedRelativePath = $this->calculateRelativePath($this->realPackageSourceDir, $this->projectVfsConfigPath);
        $this->assertEquals(
            rtrim($expectedRelativePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ComposerInstaller::CONFIG_FILE_NAME,
            $internalConfig['config_path']
        );
    }

    public function testSetupConfigDoesNotCopyIfUserFileExists()
    {
        mkdir($this->projectVfsConfigPath, 0777, true); // VFS project config dir
        $targetUserOrmYml = $this->projectVfsConfigPath . '/' . ComposerInstaller::CONFIG_FILE_NAME;
        $originalContent = 'original_user_content: true';
        file_put_contents($targetUserOrmYml, $originalContent); // Create VFS target with content
        $this->assertFileExists($targetUserOrmYml);

        $event = $this->mockEvent();
        // No need to copy example.yml to real path as it shouldn't be read for user config.
        // Still need realPackageConfigDir for package's own config.yml output.
        // (setUp ensures realPackageConfigDir exists)

        $result = ComposerInstaller::setupConfig($event);
        $this->assertTrue($result);

        $this->assertEquals($originalContent, file_get_contents($targetUserOrmYml), "Original VFS user file should not be overwritten.");

        $this->assertFileExists($this->realPackageInternalConfigYmlPath); // Real FS
        $internalConfig = Yaml::parseFile($this->realPackageInternalConfigYmlPath);
        $expectedRelativePath = $this->calculateRelativePath($this->realPackageSourceDir, $this->projectVfsConfigPath);
        $this->assertEquals(
            rtrim($expectedRelativePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ComposerInstaller::CONFIG_FILE_NAME,
            $internalConfig['config_path']
        );
    }

    public function testSetupConfigMkdirFails()
    {
        $event = $this->mockEvent();
        // Make VFS root read-only. $projectVfsConfigPath is vfs://root/config
        // mkdir for vfs://root/config should fail if vfs://root is not writable.
        $this->root->chmod(0555);

        $this->expectOutputRegex('/WARNING:: Cannot create config folder in application root:: .*config'. preg_quote(DIRECTORY_SEPARATOR) .'/');
        $result = ComposerInstaller::setupConfig($event);
        $this->assertFalse($result);

        $this->root->chmod(0777); // Restore permissions for tearDown and other tests
    }
}

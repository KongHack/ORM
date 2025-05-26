<?php

namespace GCWorld\ORM\Tests\Core;

use GCWorld\ORM\Core\CreateAuditTable;
use GCWorld\ORM\Core\Builder; // Original Builder for BUILDER_VERSION and getDataModelDirectory
use GCWorld\ORM\CommonLoader;
use GCWorld\ORM\Config; // For setting up underlying config for CommonLoader
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Yaml\Yaml;
use PDOException;

// Testable Builder to allow overriding getDataModelDirectory for CreateAuditTable's use
class CreateAuditTable_TestableBuilder extends Builder
{
    public static ?string $vfsDataModelPath = null;

    public static function getDataModelDirectory(): string
    {
        if (self::$vfsDataModelPath !== null) {
            return self::$vfsDataModelPath;
        }
        // Fallback to parent if not set, though for these tests it should always be set.
        return parent::getDataModelDirectory();
    }
}


class CreateAuditTableTest extends TestCase
{
    use ProphecyTrait;

    private vfsStreamDirectory $root;
    private string $vfsPath;

    private string $realPackageConfigDir;
    private string $realPackagePointerConfigYmlPath;

    private $sourceDbMock;
    private $destinationDbMock;
    private $commonMock;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->vfsPath = vfsStream::url('root');

        // Real path for package's config/config.yml (pointer file for CommonLoader)
        $configClassReflector = new \ReflectionClass(Config::class);
        $realPackageSrcDir = dirname($configClassReflector->getFileName());
        $this->realPackageConfigDir = dirname($realPackageSrcDir) . DIRECTORY_SEPARATOR . 'config';
        $this->realPackagePointerConfigYmlPath = $this->realPackageConfigDir . DIRECTORY_SEPARATOR . 'config.yml';

        if (!is_dir($this->realPackageConfigDir)) {
            mkdir($this->realPackageConfigDir, 0777, true);
        }
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }

        // Setup VFS for datamodel SQL files (used by CreateAuditTable via Builder::getDataModelDirectory)
        CreateAuditTable_TestableBuilder::$vfsDataModelPath = $this->vfsPath . '/datamodel/audit/';
        mkdir(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/', 0777, true);
        file_put_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'master.sql', "-- master.sql for __REPLACE__"); // master.sql is not used by CreateAuditTable directly
        file_put_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'source.sql', "CREATE TABLE __REPLACE__ (log_id INT PRIMARY KEY); -- source.sql content");
        file_put_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/1.sql', "ALTER TABLE __REPLACE__ ADD COLUMN rev1_col VARCHAR(255); -- revision 1");
        // Assuming Builder::BUILDER_VERSION is 3 for these tests, based on Builder.php
        // Revisions should be < BUILDER_VERSION to be applied. So rev 2 will be applied if BUILDER_VERSION = 3
        file_put_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/2.sql', "ALTER TABLE __REPLACE__ ADD COLUMN rev2_col INT; -- revision 2");
        file_put_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/3.sql', "ALTER TABLE __REPLACE__ ADD COLUMN rev3_col DATE; -- revision 3 (will not be applied if BUILDER_VERSION = 3)");


        // Mocks for database connections
        $this->sourceDbMock = $this->prophesize(DatabaseInterface::class);
        $this->destinationDbMock = $this->prophesize(DatabaseInterface::class);

        // Mock CommonInterface and its getConfig('audit')
        $this->commonMock = $this->prophesize(CommonInterface::class);
        $this->commonMock->getConfig('audit')->willReturn([
            'prefix' => '_Audit_',
            // Add other audit config keys if CreateAuditTable uses them, but it seems only 'prefix' is used.
        ]);

        // Set up CommonLoader to use this commonMock
        // This requires a pointer config for Config, then CommonLoader uses that Config.
        $this->setupUnderlyingConfigAndCommonLoader();
    }

    private function setupUnderlyingConfigAndCommonLoader(): void
    {
        // Create a VFS user config file that Config will load via the real pointer.
        // This user config can be minimal as CommonLoader is mocked for getConfig('audit').
        $userConfigVfsPath = $this->vfsPath . '/commonloader_user_config.yml';
        $userConfigData = [
            'version' => Config::VERSION,
            'general' => ['common' => 'IrrelevantCommonClass', 'user' => 'IrrelevantUserClass'],
            // The audit config itself will come from the mocked CommonInterface.
        ];
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $pointerConfig = ['config_path' => $relativePathToVfsUserConfig];
        file_put_contents($this->realPackagePointerConfigYmlPath, Yaml::dump($pointerConfig));
        
        // Now, ensure CommonLoader uses our mocked CommonInterface
        CommonLoader::setCommonObject($this->commonMock->reveal());
    }


    protected function tearDown(): void
    {
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }
        if (is_dir($this->realPackageConfigDir) && count(scandir($this->realPackageConfigDir)) <= 2) {
            rmdir($this->realPackageConfigDir);
        }
        CreateAuditTable_TestableBuilder::$vfsDataModelPath = null; // Reset VFS path
        
        // Reset CommonLoader's static object
        $reflection = new \ReflectionClass(CommonLoader::class);
        $commonProp = $reflection->getProperty('common');
        $commonProp->setAccessible(true);
        $commonProp->setValue(null, null);
    }

    private function calculateRelativePath(string $from, string $to): string // Helper
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

    public function testBuildTableNewTableAppliesSourceAndRevisions()
    {
        $sourceTableName = 'my_user_table';
        $auditPrefix = '_Audit_';
        $expectedAuditTableName = $auditPrefix . $sourceTableName;

        // Expected SQL executions
        $sourceSql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'source.sql'));
        $this->destinationDbMock->exec($sourceSql)->shouldBeCalledOnce();
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '0')->shouldBeCalledOnce();

        // Revisions (assuming Builder::BUILDER_VERSION is 3, from Builder.php)
        // Revision 1
        $rev1Sql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/1.sql'));
        $this->destinationDbMock->exec($rev1Sql)->shouldBeCalledOnce();
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '1')->shouldBeCalledOnce();
        // Revision 2
        $rev2Sql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/2.sql'));
        $this->destinationDbMock->exec($rev2Sql)->shouldBeCalledOnce();
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '2')->shouldBeCalledOnce();
        
        // Revision 3 should NOT be called if Builder::BUILDER_VERSION = 3 because loop is < BUILDER_VERSION

        $createAudit = new CreateAuditTable($this->sourceDbMock->reveal(), $this->destinationDbMock->reveal());
        $createAudit->buildTable($sourceTableName);
    }

    public function testBuildTableSkipsIfTableNameHasPrefix()
    {
        $prefixedTableName = '_Audit_my_user_table'; // Already prefixed

        $this->destinationDbMock->exec(Argument::any())->shouldNotBeCalled();
        $this->destinationDbMock->setTableComment(Argument::any(), Argument::any())->shouldNotBeCalled();

        $createAudit = new CreateAuditTable($this->sourceDbMock->reveal(), $this->destinationDbMock->reveal());
        $createAudit->buildTable($prefixedTableName);
    }
    
    public function testBuildTableRevisionColumnAlreadyExistsContinues()
    {
        $sourceTableName = 'table_rev_dup_col';
        $auditPrefix = '_Audit_';
        $expectedAuditTableName = $auditPrefix . $sourceTableName;

        $sourceSql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'source.sql'));
        $this->destinationDbMock->exec($sourceSql)->shouldBeCalledOnce();
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '0')->shouldBeCalledOnce();

        // Revision 1 throws "Column already exists"
        $rev1Sql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/1.sql'));
        $this->destinationDbMock->exec($rev1Sql)
            ->willThrow(new PDOException("SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'rev1_col'"))
            ->shouldBeCalledOnce();
        // setTableComment for rev1 should NOT be called
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '1')->shouldNotBeCalled();

        // Revision 2 should still be applied
        $rev2Sql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/2.sql'));
        $this->destinationDbMock->exec($rev2Sql)->shouldBeCalledOnce();
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '2')->shouldBeCalledOnce();

        $createAudit = new CreateAuditTable($this->sourceDbMock->reveal(), $this->destinationDbMock->reveal());
        $createAudit->buildTable($sourceTableName); // No overall exception expected
    }

    public function testBuildTableRevisionOtherPdoExceptionPropagates()
    {
        $sourceTableName = 'table_rev_other_error';
        $auditPrefix = '_Audit_';
        $expectedAuditTableName = $auditPrefix . $sourceTableName;

        $sourceSql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'source.sql'));
        $this->destinationDbMock->exec($sourceSql)->shouldBeCalledOnce();
        $this->destinationDbMock->setTableComment($expectedAuditTableName, '0')->shouldBeCalledOnce();

        // Revision 1 throws a generic PDOException
        $rev1Sql = str_replace('__REPLACE__', $expectedAuditTableName, file_get_contents(CreateAuditTable_TestableBuilder::$vfsDataModelPath . 'revisions/1.sql'));
        $genericPdoException = new PDOException("Generic SQL error unrelated to duplicate column");
        $this->destinationDbMock->exec($rev1Sql)
            ->willThrow($genericPdoException)
            ->shouldBeCalledOnce();
        
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("Generic SQL error unrelated to duplicate column");

        $createAudit = new CreateAuditTable($this->sourceDbMock->reveal(), $this->destinationDbMock->reveal());
        $createAudit->buildTable($sourceTableName);
    }
}

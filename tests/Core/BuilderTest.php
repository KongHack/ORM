<?php

namespace GCWorld\ORM\Tests\Core;

use GCWorld\ORM\Core\Builder;
use GCWorld\ORM\Config;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Yaml\Yaml;
use PDOStatement; // For mocking PDOStatement

// Testable Builder to allow overriding getDataModelDirectory
class TestableBuilder extends Builder
{
    public static ?string $vfsDataModelPath = null;

    public static function getDataModelDirectory(): string
    {
        if (self::$vfsDataModelPath !== null) {
            return self::$vfsDataModelPath;
        }
        return parent::getDataModelDirectory();
    }
}


class BuilderTest extends TestCase
{
    use ProphecyTrait;

    private vfsStreamDirectory $root;
    private string $vfsPath;

    private string $realPackageConfigDir;
    private string $realPackagePointerConfigYmlPath;

    private $commonMock;
    private $dbMock;    // Mock for the main database connection
    private $auditDbMock; // Mock for the audit database connection

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->vfsPath = vfsStream::url('root');

        // Real path for package's config/config.yml (pointer file)
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

        // Setup VFS for datamodel SQL files
        TestableBuilder::$vfsDataModelPath = $this->vfsPath . '/datamodel/audit/';
        mkdir(TestableBuilder::$vfsDataModelPath . 'revisions/', 0777, true);
        file_put_contents(TestableBuilder::$vfsDataModelPath . 'master.sql', "CREATE TABLE __REPLACE__ (id INT PRIMARY KEY, audit_table VARCHAR(255)); -- master.sql content");
        file_put_contents(TestableBuilder::$vfsDataModelPath . 'source.sql', "CREATE TABLE __REPLACE__ (id INT PRIMARY KEY, log_data TEXT); -- source.sql content");
        file_put_contents(TestableBuilder::$vfsDataModelPath . 'revisions/1.sql', "ALTER TABLE __REPLACE__ ADD COLUMN revision1_col VARCHAR(255); -- revision 1");
        file_put_contents(TestableBuilder::$vfsDataModelPath . 'revisions/2.sql', "ALTER TABLE __REPLACE__ ADD COLUMN revision2_col INT; -- revision 2, should be < BUILDER_VERSION");


        // Common Mocks
        $this->commonMock = $this->prophesize(CommonInterface::class);
        $this->dbMock = $this->prophesize(DatabaseInterface::class);
        $this->auditDbMock = $this->prophesize(DatabaseInterface::class);

        // Default behavior for commonMock
        $this->commonMock->getDatabase()->willReturn($this->dbMock->reveal());
        // Default audit config (can be overridden per test)
        $this->commonMock->getConfig('audit')->willReturn([
            'enable'     => true,
            'connection' => 'audit_conn_alias', // Alias for audit DB
            'database'   => 'vfs_audit_db_name',    // Name of the audit database
            'prefix'     => '_Audit_',
        ]);
        $this->commonMock->getDatabase('audit_conn_alias')->willReturn($this->auditDbMock->reveal());
    }

    protected function tearDown(): void
    {
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }
        if (is_dir($this->realPackageConfigDir) && count(scandir($this->realPackageConfigDir)) <= 2) {
            rmdir($this->realPackageConfigDir);
        }
        TestableBuilder::$vfsDataModelPath = null; // Reset VFS path for datamodel
    }

    private function createRealPackagePointerConfig(array $userConfigData): void
    {
        $userConfigVfsPath = $this->vfsPath . '/builder_test_user_config.yml';
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $pointerConfig = ['config_path' => $relativePathToVfsUserConfig];
        file_put_contents($this->realPackagePointerConfigYmlPath, Yaml::dump($pointerConfig));
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

    public function testGetDataModelDirectory()
    {
        // Test the static method directly from the original class first
        $path = Builder::getDataModelDirectory();
        $this->assertStringEndsWith('datamodel'.DIRECTORY_SEPARATOR.'audit'.DIRECTORY_SEPARATOR, $path);

        // Test the override in TestableBuilder
        TestableBuilder::$vfsDataModelPath = 'vfs://fake/path/';
        $this->assertEquals('vfs://fake/path/', TestableBuilder::getDataModelDirectory());
    }

    public function testConstructorInitializesProperties()
    {
        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true], // Enable global audit
            // tables config can be empty for this test
        ]);

        // Common mock already set up in setUp for default audit config
        // We can vary commonMock's getConfig('audit') if needed for specific constructor tests

        $builder = new TestableBuilder($this->commonMock->reveal());

        // Use reflection to check readonly properties if necessary, or check behavior
        $this->assertInstanceOf(Builder::class, $builder);
        // Further assertions can be made on the effects of the constructor,
        // e.g. if $this->doAudit is true/false based on config.
    }

    public function testRunReturnsEarlyIfGlobalAuditDisabled()
    {
        $this->createRealPackagePointerConfig([
            'general' => ['audit' => false], // Global audit disabled
        ]);
        // commonMock getConfig('audit') should not be called, nor getDatabase for audit
        $this->commonMock->getConfig('audit')->shouldNotBeCalled();
        $this->commonMock->getDatabase('audit_conn_alias')->shouldNotBeCalled();

        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run(); // Should return early

        // Add assertions to ensure no audit DB interactions happened
        $this->auditDbMock->tableExists(Argument::any())->shouldNotHaveBeenCalled();
    }

    public function testRunReturnsEarlyIfAuditConfigDisabled()
    {
         $this->createRealPackagePointerConfig([
            'general' => ['audit' => true], // Global audit enabled
        ]);
        $this->commonMock->getConfig('audit')->willReturn([
            'enable' => false, // Audit specifically disabled here
            // other keys like connection, database, prefix might be present but enable=false is key
        ]);
        // getDatabase for audit connection should NOT be called if enable is false from audit config
        $this->commonMock->getDatabase(Argument::any())->willReturn($this->dbMock->reveal())->shouldBeCalledTimes(1); // Only main DB

        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run();

        $this->auditDbMock->tableExists(Argument::any())->shouldNotHaveBeenCalled();
    }
    
    public function testRunReturnsEarlyIfAuditDbNotResolved()
    {
        $this->createRealPackagePointerConfig(['general' => ['audit' => true]]);
        $this->commonMock->getConfig('audit')->willReturn([
            'enable'     => true,
            'connection' => 'audit_db_conn_alias_error', // This connection will fail
            'database'   => 'vfs_audit_db_name',
            'prefix'     => '_Audit_',
        ]);
        // Make getDatabase throw an exception for the audit connection
        $this->commonMock->getDatabase('audit_db_conn_alias_error')->willThrow(new \Exception("DB connection failed"));

        $builder = new TestableBuilder($this->commonMock->reveal()); // Constructor will catch exception, set doAudit=false
        $builder->run(); // Should return early because doAudit became false

        $this->auditDbMock->tableExists(Argument::any())->shouldNotHaveBeenCalled();
    }

    public function testRunCreatesMasterTableIfNotExists()
    {
        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [], // No tables to audit, but master table should still be checked/created
        ]);

        $masterTableFullName = 'vfs_audit_db_name._Audit_GCAuditMaster';
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(false)->shouldBeCalledOnce();
        
        // Expect master.sql content to be executed
        $expectedSqlMaster = str_replace('__REPLACE__', $masterTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'master.sql'));
        $this->auditDbMock->exec($expectedSqlMaster)->shouldBeCalledOnce();
        
        // Mock for the INFORMATION_SCHEMA.TABLES query (to find user tables)
        // This will be called even if no user tables, should return empty set
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute(Argument::type('array'))->shouldBeCalled();
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([])->shouldBeCalled();
        $this->dbMock->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = :type")
             ->willReturn($stmtUserTables->reveal());

        // Mock for existing audit entries in master table (empty)
        $stmtExistingAudit = $this->prophesize(PDOStatement::class);
        $stmtExistingAudit->execute()->shouldBeCalled();
        $stmtExistingAudit->fetch()->willReturn(false); // No existing entries
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingAudit->reveal());


        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run('main_db_schema_name'); // Provide a schema name
    }

    // More tests to follow for:
    // - Master table needing audit_pk_set column
    // - New user table processing (source.sql, master log insert)
    // - User table schema upgrade (revisions)
    // - User table audit_ignore
    // - Audit table prefix skipping

    public function testRunAltersMasterTableIfNeeded()
    {
        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [],
        ]);
        $masterTableFullName = 'vfs_audit_db_name._Audit_GCAuditMaster';

        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true)->shouldBeCalledOnce();

        // Mock SHOW COLUMNS to not include 'audit_pk_set'
        $stmtShowCols = $this->prophesize(PDOStatement::class);
        $stmtShowCols->execute()->shouldBeCalled();
        $stmtShowCols->fetchAll()->willReturn([['Field' => 'id'], ['Field' => 'audit_table']])->shouldBeCalled();
        $this->auditDbMock->prepare("SHOW COLUMNS FROM {$masterTableFullName}")->willReturn($stmtShowCols->reveal());

        // Expect ALTER TABLE to be called
        $expectedAlterSql = "ALTER TABLE {$masterTableFullName} ADD `audit_pk_set` TINYINT(1) NOT NULL DEFAULT '0' AFTER `audit_table`";
        $this->auditDbMock->exec($expectedAlterSql)->shouldBeCalledOnce();

        // Mock for INFORMATION_SCHEMA.TABLES (empty)
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute(Argument::type('array'))->shouldBeCalled();
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([])->shouldBeCalled();
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());
        
        // Mock for existing audit entries in master table (empty)
        $stmtExistingAudit = $this->prophesize(PDOStatement::class);
        $stmtExistingAudit->execute()->shouldBeCalled();
        $stmtExistingAudit->fetch()->willReturn(false); // No existing entries
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingAudit->reveal());

        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run('main_db_schema_name');
    }

    public function testRunProcessesNewUserTable()
    {
        $schemaName = 'my_schema';
        $userTableName = 'new_user_table';
        $auditPrefix = '_Audit_';
        $auditDbName = 'vfs_audit_db_name';
        $auditTableBaseName = $auditPrefix . $userTableName;
        $auditTableFullName = $auditDbName . '.' . $auditTableBaseName;
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';

        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [$userTableName => []], // No specific audit_ignore for this table
        ]);
        $this->commonMock->getConfig('audit')->willReturn([ // Ensure audit config is consistent
            'enable'     => true, 'connection' => 'audit_conn_alias',
            'database'   => $auditDbName, 'prefix'     => $auditPrefix,
        ]);

        // Master table exists and is up-to-date
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowCols = $this->prophesize(PDOStatement::class);
        $stmtShowCols->execute()->shouldBeCalled();
        $stmtShowCols->fetchAll()->willReturn([['Field' => 'audit_pk_set']])->shouldBeCalled(); // audit_pk_set exists
        $this->auditDbMock->prepare("SHOW COLUMNS FROM {$masterTableFullName}")->willReturn($stmtShowCols->reveal());

        // No existing audit log for this table in master
        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->shouldBeCalled();
        $stmtExistingMasterLog->fetch()->willReturn(false)->shouldBeCalled(); // No existing entry
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingMasterLog->reveal());
        
        // User table exists in main DB
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute([':schema' => $schemaName, ':type' => 'BASE TABLE'])->shouldBeCalled();
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([[$userTableName]])->shouldBeCalled();
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());

        // Audit table for user_table does NOT exist yet
        $this->auditDbMock->tableExists($auditTableFullName)->willReturn(false)->shouldBeCalledOnce();

        // Expect source.sql to be executed for new audit table
        $expectedSourceSql = str_replace('__REPLACE__', $auditTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'source.sql'));
        $this->auditDbMock->exec($expectedSourceSql)->shouldBeCalledOnce();
        $this->auditDbMock->setTableComment($auditTableFullName, '0')->shouldBeCalledOnce();

        // Mock SHOW COLUMNS for the main user table (needed for PK check, though PK logic is disabled)
        $stmtShowUserTableCols = $this->prophesize(PDOStatement::class);
        $stmtShowUserTableCols->execute()->shouldBeCalled();
        $stmtShowUserTableCols->fetchAll()->willReturn([['Field' => 'id', 'Key' => 'PRI', 'Type' => 'int(11)']])->shouldBeCalled();
        $this->dbMock->prepare("SHOW COLUMNS FROM {$userTableName}")->willReturn($stmtShowUserTableCols->reveal());

        // Expect INSERT into master log table
        $stmtInsertMaster = $this->prophesize(PDOStatement::class);
        $stmtInsertMaster->execute([
            ':audit_schema'  => $auditDbName, // Schema is the audit DB name itself
            ':audit_table'   => $auditTableBaseName,
            ':audit_version' => TestableBuilder::BUILDER_VERSION,
        ])->shouldBeCalledOnce();
        $stmtInsertMaster->closeCursor()->shouldBeCalledOnce();
        $this->auditDbMock->prepare(Argument::containingString("INSERT INTO {$masterTableFullName}"))
             ->willReturn($stmtInsertMaster->reveal())->shouldBeCalledOnce();
        
        // Expect final table comment update to BUILDER_VERSION
        $this->auditDbMock->setTableComment($auditTableFullName, TestableBuilder::BUILDER_VERSION)->shouldBeCalledOnce();


        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run($schemaName);
    }

    public function testRunUpgradesExistingAuditTableSchema()
    {
        $schemaName = 'my_schema';
        $userTableName = 'existing_user_table';
        $auditPrefix = '_Audit_';
        $auditDbName = 'vfs_audit_db_name';
        $auditTableBaseName = $auditPrefix . $userTableName;
        $auditTableFullName = $auditDbName . '.' . $auditTableBaseName;
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';

        // Revisions 1 and 2 are in VFS. Builder version is 3. Let's say current audit_version is 0.
        $currentAuditVersionInDb = 0;

        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [$userTableName => []],
        ]);
        $this->commonMock->getConfig('audit')->willReturn([
            'enable' => true, 'connection' => 'audit_conn_alias',
            'database' => $auditDbName, 'prefix' => $auditPrefix,
        ]);

        // Master table exists and is up-to-date
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowColsMaster = $this->prophesize(PDOStatement::class);
        $stmtShowColsMaster->execute()->shouldBeCalled();
        $stmtShowColsMaster->fetchAll()->willReturn([['Field' => 'audit_pk_set']])->shouldBeCalled();
        $this->auditDbMock->prepare("SHOW COLUMNS FROM {$masterTableFullName}")->willReturn($stmtShowColsMaster->reveal());

        // Existing audit log for this table in master, with old version
        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->shouldBeCalled();
        $stmtExistingMasterLog->fetch()->willReturn([
            'audit_schema' => $auditDbName,
            'audit_table' => $auditTableBaseName,
            'audit_version' => $currentAuditVersionInDb, // Old version
            'audit_pk_set' => 1, // Assume PK is set
        ])->shouldBeCalled();
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingMasterLog->reveal());
        
        // User table exists in main DB
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute([':schema' => $schemaName, ':type' => 'BASE TABLE'])->shouldBeCalled();
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([[$userTableName]])->shouldBeCalled();
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());

        // Audit table for user_table EXISTS
        $this->auditDbMock->tableExists($auditTableFullName)->willReturn(true)->shouldBeCalledOnce();

        // Expect revision SQL to be executed
        // Revision 1.sql
        $revision1Sql = str_replace('__REPLACE__', $auditTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'revisions/1.sql'));
        $this->auditDbMock->exec($revision1Sql)->shouldBeCalledOnce();
        $this->auditDbMock->setTableComment($auditTableFullName, '1')->shouldBeCalledOnce();
        
        // Revision 2.sql
        $revision2Sql = str_replace('__REPLACE__', $auditTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'revisions/2.sql'));
        $this->auditDbMock->exec($revision2Sql)->shouldBeCalledOnce();
        $this->auditDbMock->setTableComment($auditTableFullName, '2')->shouldBeCalledOnce();

        // Mock SHOW COLUMNS for the main user table (for PK check)
        $stmtShowUserTableCols = $this->prophesize(PDOStatement::class);
        $stmtShowUserTableCols->execute()->shouldBeCalled();
        $stmtShowUserTableCols->fetchAll()->willReturn([['Field' => 'id', 'Key' => 'PRI', 'Type' => 'int(11)']])->shouldBeCalled();
        $this->dbMock->prepare("SHOW COLUMNS FROM {$userTableName}")->willReturn($stmtShowUserTableCols->reveal());
        
        // Expect UPDATE on master log table
        $stmtUpdateMaster = $this->prophesize(PDOStatement::class);
        $stmtUpdateMaster->execute([
            ':audit_schema'  => $auditDbName,
            ':audit_table'   => $auditTableBaseName,
            ':audit_version' => TestableBuilder::BUILDER_VERSION,
        ])->shouldBeCalledOnce();
        $stmtUpdateMaster->closeCursor()->shouldBeCalledOnce();
        // The SQL for INSERT ON DUPLICATE KEY UPDATE will be prepared
        $this->auditDbMock->prepare(Argument::containingString("INSERT INTO {$masterTableFullName}"))
             ->willReturn($stmtUpdateMaster->reveal())->shouldBeCalledOnce();
        
        // Expect final table comment update to BUILDER_VERSION if it changed from last revision
        if (TestableBuilder::BUILDER_VERSION > 2) { // Assuming last revision applied was 2
            $this->auditDbMock->setTableComment($auditTableFullName, TestableBuilder::BUILDER_VERSION)->shouldBeCalledOnce();
        }


        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run($schemaName);
    }

    public function testRunSkipsTableIfAuditIgnoreTrueInConfig()
    {
        $schemaName = 'my_schema';
        $userTableNameIgnored = 'ignored_table';
        $auditPrefix = '_Audit_';
        $auditDbName = 'vfs_audit_db_name';
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';

        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [
                $userTableNameIgnored => ['audit_ignore' => true], // This table should be ignored
            ],
        ]);
        $this->commonMock->getConfig('audit')->willReturn([
            'enable' => true, 'connection' => 'audit_conn_alias',
            'database' => $auditDbName, 'prefix' => $auditPrefix,
        ]);

        // Master table exists and is up-to-date (setup to avoid early exit for master table issues)
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowColsMaster = $this->prophesize(PDOStatement::class);
        $stmtShowColsMaster->execute()->shouldBeCalled();
        $stmtShowColsMaster->fetchAll()->willReturn([['Field' => 'audit_pk_set']])->shouldBeCalled();
        $this->auditDbMock->prepare("SHOW COLUMNS FROM {$masterTableFullName}")->willReturn($stmtShowColsMaster->reveal());
        
        // No existing audit log for this table in master (setup)
        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->shouldBeCalled();
        $stmtExistingMasterLog->fetch()->willReturn(false)->shouldBeCalled();
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingMasterLog->reveal());

        // User table (ignored_table) exists in main DB
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute([':schema' => $schemaName, ':type' => 'BASE TABLE'])->shouldBeCalled();
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([[$userTableNameIgnored]])->shouldBeCalled();
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());

        // Crucially, these should NOT be called for the ignored_table
        $auditTableFullNameIgnored = $auditDbName . '.' . $auditPrefix . $userTableNameIgnored;
        $this->auditDbMock->tableExists($auditTableFullNameIgnored)->shouldNotBeCalled();
        $this->auditDbMock->exec(Argument::any())->shouldNotBeCalled(); // No ALTER, no CREATE from source.sql/master.sql/revisions
        $this->auditDbMock->setTableComment(Argument::any(), Argument::any())->shouldNotBeCalled();
        // No insert/update into GCAuditMaster for this table
        $this->auditDbMock->prepare(Argument::containingString("INSERT INTO {$masterTableFullName}"))
             ->shouldNotBeCalled(); // Or if called, execute for this table should not happen.

        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run($schemaName);
    }

    public function testRunSkipsAuditPrefixedTablesFromUserDb()
    {
        $schemaName = 'my_schema';
        $auditPrefix = '_Audit_';
        $auditTableInUserDb = $auditPrefix . 'some_user_table_detail'; // A table that looks like an audit table
        $auditDbName = 'vfs_audit_db_name';
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';

        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [], // No specific config for this audit-like table
        ]);
         $this->commonMock->getConfig('audit')->willReturn([
            'enable' => true, 'connection' => 'audit_conn_alias',
            'database' => $auditDbName, 'prefix' => $auditPrefix,
        ]);

        // Master table setup
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowColsMaster = $this->prophesize(PDOStatement::class);
        $stmtShowColsMaster->execute()->shouldBeCalled();
        $stmtShowColsMaster->fetchAll()->willReturn([['Field' => 'audit_pk_set']])->shouldBeCalled();
        $this->auditDbMock->prepare("SHOW COLUMNS FROM {$masterTableFullName}")->willReturn($stmtShowColsMaster->reveal());
        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->shouldBeCalled();
        $stmtExistingMasterLog->fetch()->willReturn(false)->shouldBeCalled();
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingMasterLog->reveal());

        // User DB lists this audit-prefixed table
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute([':schema' => $schemaName, ':type' => 'BASE TABLE'])->shouldBeCalled();
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([[$auditTableInUserDb]])->shouldBeCalled();
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());

        // Assert that no processing happens for this audit-prefixed table
        $this->auditDbMock->tableExists($auditDbName . '.' . $auditPrefix . $auditTableInUserDb)->shouldNotBeCalled();
        // No DDL or DML related to this table on audit DB
        // (exec for schema changes, setTableComment, prepare for master log insert)

        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run($schemaName);
    }

    public function testRunUsesDefaultSchemaIfSchemaArgIsNull()
    {
        $auditDbName = 'vfs_audit_db_name'; // This should be used as schema
        $auditPrefix = '_Audit_';
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';

        $this->createRealPackagePointerConfig([
            'general' => ['audit' => true],
            'tables'  => [],
        ]);
        $this->commonMock->getConfig('audit')->willReturn([
            'enable' => true, 'connection' => 'audit_conn_alias',
            'database' => $auditDbName, 'prefix' => $auditPrefix,
        ]);

        // Master table setup
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowColsMaster = $this->prophesize(PDOStatement::class);
        $stmtShowColsMaster->execute()->shouldBeCalled();
        $stmtShowColsMaster->fetchAll()->willReturn([['Field' => 'audit_pk_set']])->shouldBeCalled();
        $this->auditDbMock->prepare("SHOW COLUMNS FROM {$masterTableFullName}")->willReturn($stmtShowColsMaster->reveal());
        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->shouldBeCalled();
        $stmtExistingMasterLog->fetch()->willReturn(false)->shouldBeCalled();
        $this->auditDbMock->prepare("SELECT * FROM {$masterTableFullName}")->willReturn($stmtExistingMasterLog->reveal());

        // Expect INFORMATION_SCHEMA.TABLES query to use $auditDbName as schema
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute([':schema' => $auditDbName, ':type' => 'BASE TABLE'])->shouldBeCalled(); // Important check
        $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([])->shouldBeCalled(); // No user tables
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());
        
        // If auditDB is null in config, it would try $this->_audit->getWorkingDatabaseName()
        // Let's test that path too by making auditDB null in commonMock config for audit
        $this->commonMock->getConfig('audit')->willReturn([
            'enable' => true, 'connection' => 'audit_conn_alias',
            'database' => null, // auditDB is null
            'prefix' => $auditPrefix,
        ]);
        $this->auditDbMock->getWorkingDatabaseName()->willReturn('audit_working_db_name')->shouldBeCalled();
        $stmtUserTablesSchemaFallback = $this->prophesize(PDOStatement::class);
        $stmtUserTablesSchemaFallback->execute([':schema' => 'audit_working_db_name', ':type' => 'BASE TABLE'])->shouldBeCalled();
        $stmtUserTablesSchemaFallback->fetchAll(PDO::FETCH_NUM)->willReturn([])->shouldBeCalled();
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTablesSchemaFallback->reveal());


        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run(null); // Pass null for schema
    }
    
    public function testRunRevisionErrorHandlingNonDuplicateColumn()
    {
        $schemaName = 'my_schema';
        $userTableName = 'table_with_rev_error';
        // ... (setup similar to testRunUpgradesExistingAuditTableSchema) ...
        $auditPrefix = '_Audit_';
        $auditDbName = 'vfs_audit_db_name';
        $auditTableBaseName = $auditPrefix . $userTableName;
        $auditTableFullName = $auditDbName . '.' . $auditTableBaseName;
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';
        $currentAuditVersionInDb = 0;

        $this->createRealPackagePointerConfig([ /* ... */ 'general' => ['audit' => true], 'tables'  => [$userTableName => []],]);
        $this->commonMock->getConfig('audit')->willReturn([ /* ... */ 'enable' => true, 'connection' => 'audit_conn_alias', 'database' => $auditDbName, 'prefix' => $auditPrefix,]);
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowColsMaster = $this->prophesize(PDOStatement::class);
        $stmtShowColsMaster->execute()->willReturn(); $stmtShowColsMaster->fetchAll()->willReturn([['Field' => 'audit_pk_set']]);
        $this->auditDbMock->prepare(Argument::any())->willReturn($stmtShowColsMaster->reveal());

        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->willReturn();
        $stmtExistingMasterLog->fetch()->willReturn(['audit_schema' => $auditDbName, 'audit_table' => $auditTableBaseName, 'audit_version' => $currentAuditVersionInDb, 'audit_pk_set' => 1]);
        $this->auditDbMock->prepare(Argument::containingString("SELECT * FROM {$masterTableFullName}"))->willReturn($stmtExistingMasterLog->reveal());
        
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute(Argument::any())->willReturn(); $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([[$userTableName]]);
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());
        $this->auditDbMock->tableExists($auditTableFullName)->willReturn(true);

        // Revision 1.sql will throw a generic PDOException
        $revision1Sql = str_replace('__REPLACE__', $auditTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'revisions/1.sql'));
        $pdoException = new \PDOException("Generic SQL error");
        $this->auditDbMock->exec($revision1Sql)->willThrow($pdoException)->shouldBeCalledOnce();
        
        // Other revisions should not be attempted after a critical failure.
        // SetTableComment for revision 1 should not be called.
        $this->auditDbMock->setTableComment($auditTableFullName, '1')->shouldNotBeCalled();

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage("Generic SQL error");

        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run($schemaName);
    }

    public function testRunRevisionErrorHandlingDuplicateColumn()
    {
        $schemaName = 'my_schema';
        $userTableName = 'table_with_dup_col_rev';
        // ... (setup similar to testRunUpgradesExistingAuditTableSchema) ...
        $auditPrefix = '_Audit_';
        $auditDbName = 'vfs_audit_db_name';
        $auditTableBaseName = $auditPrefix . $userTableName;
        $auditTableFullName = $auditDbName . '.' . $auditTableBaseName;
        $masterTableFullName = $auditDbName . '.' . $auditPrefix . 'GCAuditMaster';
        $currentAuditVersionInDb = 0; // Will try to apply revision 1 and 2

        $this->createRealPackagePointerConfig([ /* ... */ 'general' => ['audit' => true], 'tables'  => [$userTableName => []],]);
        $this->commonMock->getConfig('audit')->willReturn([ /* ... */ 'enable' => true, 'connection' => 'audit_conn_alias', 'database' => $auditDbName, 'prefix' => $auditPrefix,]);
        $this->auditDbMock->tableExists($masterTableFullName)->willReturn(true);
        $stmtShowColsMaster = $this->prophesize(PDOStatement::class);
        $stmtShowColsMaster->execute()->willReturn(); $stmtShowColsMaster->fetchAll()->willReturn([['Field' => 'audit_pk_set']]);
        $this->auditDbMock->prepare(Argument::any())->willReturn($stmtShowColsMaster->reveal());

        $stmtExistingMasterLog = $this->prophesize(PDOStatement::class);
        $stmtExistingMasterLog->execute()->willReturn();
        $stmtExistingMasterLog->fetch()->willReturn(['audit_schema' => $auditDbName, 'audit_table' => $auditTableBaseName, 'audit_version' => $currentAuditVersionInDb, 'audit_pk_set' => 1]);
        $this->auditDbMock->prepare(Argument::containingString("SELECT * FROM {$masterTableFullName}"))->willReturn($stmtExistingMasterLog->reveal());
        
        $stmtUserTables = $this->prophesize(PDOStatement::class);
        $stmtUserTables->execute(Argument::any())->willReturn(); $stmtUserTables->fetchAll(PDO::FETCH_NUM)->willReturn([[$userTableName]]);
        $this->dbMock->prepare(Argument::containingString("INFORMATION_SCHEMA.TABLES"))->willReturn($stmtUserTables->reveal());
        $this->auditDbMock->tableExists($auditTableFullName)->willReturn(true);

        // Revision 1.sql will throw 'Column already exists'
        $revision1Sql = str_replace('__REPLACE__', $auditTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'revisions/1.sql'));
        $pdoDupException = new \PDOException("SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'revision1_col'");
        $this->auditDbMock->exec($revision1Sql)->willThrow($pdoDupException)->shouldBeCalledOnce();
        // setTableComment for revision 1 should NOT be called as exec failed, even if caught
        $this->auditDbMock->setTableComment($auditTableFullName, '1')->shouldNotBeCalled();


        // Revision 2.sql should still be attempted and succeed
        $revision2Sql = str_replace('__REPLACE__', $auditTableFullName, file_get_contents(TestableBuilder::$vfsDataModelPath . 'revisions/2.sql'));
        $this->auditDbMock->exec($revision2Sql)->shouldBeCalledOnce();
        $this->auditDbMock->setTableComment($auditTableFullName, '2')->shouldBeCalledOnce(); // Comment for rev 2 applied

        // Mock SHOW COLUMNS for the main user table (for PK check)
        $stmtShowUserTableCols = $this->prophesize(PDOStatement::class);
        $stmtShowUserTableCols->execute()->shouldBeCalled();
        $stmtShowUserTableCols->fetchAll()->willReturn([['Field' => 'id', 'Key' => 'PRI', 'Type' => 'int(11)']])->shouldBeCalled();
        $this->dbMock->prepare("SHOW COLUMNS FROM {$userTableName}")->willReturn($stmtShowUserTableCols->reveal());

        // Expect UPDATE on master log table with BUILDER_VERSION
        $stmtUpdateMaster = $this->prophesize(PDOStatement::class);
        $stmtUpdateMaster->execute(Argument::that(function($args) {
            return $args[':audit_version'] == TestableBuilder::BUILDER_VERSION;
        }))->shouldBeCalledOnce();
        $stmtUpdateMaster->closeCursor()->shouldBeCalledOnce();
        $this->auditDbMock->prepare(Argument::containingString("INSERT INTO {$masterTableFullName}"))
             ->willReturn($stmtUpdateMaster->reveal())->shouldBeCalledOnce();
        
        if (TestableBuilder::BUILDER_VERSION > 2) {
             $this->auditDbMock->setTableComment($auditTableFullName, TestableBuilder::BUILDER_VERSION)->shouldBeCalledOnce();
        }


        $builder = new TestableBuilder($this->commonMock->reveal());
        $builder->run($schemaName); // No exception expected overall
    }
}

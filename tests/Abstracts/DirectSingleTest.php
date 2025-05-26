<?php

namespace GCWorld\ORM\Tests\Abstracts;

use GCWorld\ORM\Abstracts\DirectSingle;
use GCWorld\ORM\CommonLoader;
use GCWorld\ORM\Config; // For setting up underlying config for CommonLoader
use GCWorld\ORM\Interfaces\AuditInterface;
use GCWorld\ORM\ORMException;
use GCWorld\ORM\ORMLogger;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use Redis; // For mocking Redis
use PDO;
use PDOStatement;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use Symfony\Component\Yaml\Yaml; // For creating pointer config

// Concrete subclass for testing DirectSingle
class ConcreteDirectSingle extends DirectSingle
{
    const CLASS_TABLE = 'test_table';
    const CLASS_PRIMARY = 'id';

    public static array $dbInfo = [
        'id'        => ['type' => 'int', 'options' => ['unsigned' => true, 'auto_increment' => true]],
        'name'      => ['type' => 'varchar', 'length' => 255],
        'email'     => ['type' => 'varchar', 'length' => 255, 'default' => null],
        'is_active' => ['type' => 'tinyint', 'length' => 1, 'default' => 0],
        'json_data' => ['type' => 'json', 'default' => null],
    ];

    // Make properties public for easier assertion without reflection,
    // or rely on get/set if testing DirectDBClass behavior.
    // For DirectSingle, properties are declared dynamically based on $dbInfo keys.
    public int $id;
    public string $name;
    public ?string $email = null;
    public int $is_active = 0;
    public ?string $json_data = null; // JSON stored as string

    // Expose protected methods for easier testing if needed, or test via public API
    public function testGet(string $key): mixed { return parent::get($key); }
    public function testSet(string $key, mixed $val): static { return parent::set($key, $val); }
    public function testGetArray(array $fields): array { return parent::getArray($fields); }
    public function testSetArray(array $data): static { return parent::setArray($data); }
    public function testSetCacheData(): void { parent::setCacheData(); }

    // Allow overriding these for specific tests
    public function setDbNameForTest(?string $name) { $this->_dbName = $name; }
    public function setCacheNameForTest(?string $name) { $this->_cacheName = $name; }
    public function setCanCacheForTest(bool $can) { $this->_canCache = $can; }
    public function setCacheTTLForTest(int $ttl) { $this->_cacheTTL = $ttl; }
    public function setCanCacheAfterPurgeForTest(bool $can) { $this->_canCacheAfterPurge = $can; }
    public function setAuditForTest(bool $audit) { $this->_audit = $audit; }
    public function setAuditHandlerForTest(?string $handler) { $this->_auditHandler = $handler; }
    public function setCanInsertForTest(bool $can) { $this->_canInsert = $can; }

    public function getInternalCommon(): CommonInterface { return $this->_common; }
    public function getInternalDb(): ?DatabaseInterface { return $this->_db; }
    public function getInternalCache(): ?Redis { return $this->_cache; }
    public function getMyNameForTest(): string { return $this->myName; }
}

class MockAuditHandler implements AuditInterface
{
    public static $logs = [];
    public function __construct(CommonInterface $common) {}
    public function storeLog(string $table, mixed $primaryId, array $before, array $after, mixed $memberId = null): int|string {
        self::$logs[] = compact('table', 'primaryId', 'before', 'after', 'memberId');
        return 1; // Mock audit log ID
    }
}


class DirectSingleTest extends TestCase
{
    use ProphecyTrait;

    private string $realPackageConfigDir;
    private string $realPackagePointerConfigYmlPath;
    private vfsStreamDirectory $root;
    private string $vfsPath;


    private $commonMock;
    private $dbMock;
    private $redisMock;
    private $pdoStatementMock;
    private TestHandler $logHandler;

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->vfsPath = vfsStream::url('root');

        // Real path for package's config/config.yml (pointer file for Config used by CommonLoader)
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
        // Create a minimal pointer config file, specific tests can override user config data
        $this->setupPointerConfigMinimal();


        $this->commonMock = $this->prophesize(CommonInterface::class);
        $this->dbMock = $this->prophesize(DatabaseInterface::class);
        $this->redisMock = $this->prophesize(Redis::class);
        $this->pdoStatementMock = $this->prophesize(PDOStatement::class);

        $this->commonMock->getDatabase(Argument::any())->willReturn($this->dbMock->reveal());
        $this->commonMock->getCache(Argument::any())->willReturn($this->redisMock->reveal());
        // Default config for audit (used by DirectSingle constructor for _auditHandler)
        $this->commonMock->getConfig('audit')->willReturn(['prefix' => '_Audit_', 'enable' => true]);


        CommonLoader::setCommonObject($this->commonMock->reveal());

        // Setup Logger
        $this->logHandler = new TestHandler();
        $logger = new Logger('ORMLoggerTest');
        $logger->pushHandler($this->logHandler);
        ORMLogger::setLogger($logger);

        MockAuditHandler::$logs = []; // Reset static log storage
    }

    protected function tearDown(): void
    {
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }
        if (is_dir($this->realPackageConfigDir) && count(scandir($this->realPackageConfigDir)) <= 2) {
            rmdir($this->realPackageConfigDir);
        }
        
        // Reset CommonLoader's static object
        $reflection = new \ReflectionClass(CommonLoader::class);
        $commonProp = $reflection->getProperty('common');
        $commonProp->setAccessible(true);
        $commonProp->setValue(null, null);

        ORMLogger::setLogger(null); // Reset logger
    }

    private function setupPointerConfigMinimal(): void
    {
        $userConfigVfsPath = $this->vfsPath . '/minimal_user_config.yml';
        $userConfigData = [
            'version' => Config::VERSION,
            'general' => ['common' => 'MinimalCommon', 'user' => 'MinimalUser'],
        ];
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $pointerConfig = ['config_path' => $relativePathToVfsUserConfig];
        file_put_contents($this->realPackagePointerConfigYmlPath, Yaml::dump($pointerConfig));
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

    public function testConstructorNoIdNoDefaults()
    {
        $obj = new ConcreteDirectSingle();
        $this->assertInstanceOf(ConcreteDirectSingle::class, $obj);
        $this->assertNull($obj->id); // Assuming int property $id is not initialized by PHP to 0
        $this->assertNull($obj->name); // Assuming string property $name is not initialized
        $this->assertFalse($obj->_hasChanged());
    }

    public function testConstructorWithDefaults()
    {
        $defaults = ['name' => 'Test Name', 'email' => 'test@example.com', 'is_active' => 1];
        // Constructor only takes ($primary_id = null, ?array $defaults = null)
        // The $defaults in constructor are what's fetched from DB or cache.
        // To set initial values *without* loading, we'd call `setArray` or individual setters
        // after construction with no ID.
        // The constructor's $defaults param is for when data is passed in (e.g. from a factory).
        // Let's simulate this by calling it with null ID and $defaults.
        // However, the current constructor logic prioritizes loading via ID if ID is given,
        // and if no ID, it just initializes. The $defaults parameter in the constructor
        // seems to be used for data *loaded from DB* when an ID is provided.
        // So, to test "initialization with data without DB load", one would do:
        // $obj = new ConcreteDirectSingle(); $obj->testSetArray($defaults);
        // Let's test the constructor as it is: if defaults are passed, how are they used?
        // The constructor logic: if ID given, tries cache then DB. DB result becomes $defaults.
        // If no ID, it uses PHP property defaults. The $defaults param isn't directly assigned.
        // This seems to be a misunderstanding in my plan vs. the actual constructor.
        // Let's re-evaluate constructor with $defaults:
        // `protected function __construct(mixed $primary_id = null, ?array $defaults = null)`
        // The $defaults parameter is assigned if `fetch(PDO::FETCH_ASSOC)` returns data.
        // It is NOT for passing arbitrary data to pre-fill the object if $primary_id is null.

        // Test Case: Construction with primary_id, and $defaults is what DB would return
        $pk = 1;
        $dbData = ['id' => $pk, 'name' => 'DB Name', 'email' => 'db@example.com', 'is_active' => 1, 'json_data' => null];
        
        $this->redisMock->hGet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)->willReturn(false); // Cache miss
        $this->dbMock->prepare("SELECT  * FROM test_table WHERE id = :id")
            ->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn($dbData)->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();
        
        // For setCacheData
        $this->redisMock->hSet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk, Argument::type('string'))->shouldBeCalled();

        $obj = new ConcreteDirectSingle($pk); // $defaults param is not used by caller here
        $this->assertEquals($pk, $obj->id);
        $this->assertEquals('DB Name', $obj->name);
        $this->assertFalse($obj->_hasChanged());
    }


    public function testConstructorCacheHit()
    {
        $pk = 1;
        $cachedData = ['id' => $pk, 'name' => 'Cached Name', 'email' => 'cache@example.com', 'is_active' => 0, 'json_data' => '{"foo":"bar"}'];
        $cachedDataWithOrmTime = $cachedData + ['ORM_TIME' => time() + 3600]; // Not expired
        
        $this->redisMock->hGet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)
            ->willReturn(serialize($cachedDataWithOrmTime));
        
        $this->dbMock->prepare(Argument::any())->shouldNotBeCalled(); // DB should not be hit

        $obj = new ConcreteDirectSingle($pk);
        $this->assertEquals($pk, $obj->id);
        $this->assertEquals('Cached Name', $obj->name);
        $this->assertEquals('cache@example.com', $obj->email);
        $this->assertEquals(0, $obj->is_active);
        $this->assertEquals('{"foo":"bar"}', $obj->json_data);
        $this->assertFalse($obj->_hasChanged());
    }
    
    public function testConstructorCacheMissExpiredCache()
    {
        $pk = 1;
        $cachedData = ['id' => $pk, 'name' => 'Expired Cache Name'];
        $cachedDataWithOrmTime = $cachedData + ['ORM_TIME' => time() - 3600]; // Expired
        
        $this->redisMock->hGet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)
            ->willReturn(serialize($cachedDataWithOrmTime));
        $this->redisMock->hDel('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)->shouldBeCalled(); // Expired cache deleted


        $dbData = ['id' => $pk, 'name' => 'DB Name After Expired Cache', 'email' => 'db@example.com', 'is_active' => 1, 'json_data' => null];
        $this->dbMock->prepare("SELECT  * FROM test_table WHERE id = :id")
            ->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn($dbData)->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();
        $this->redisMock->hSet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk, Argument::type('string'))->shouldBeCalled();


        $obj = new ConcreteDirectSingle($pk);
        $this->assertEquals('DB Name After Expired Cache', $obj->name);
    }


    public function testConstructorDbMissCanInsertFalseThrowsException()
    {
        $pk = 2;
        $this->redisMock->hGet(Argument::any(), Argument::any())->willReturn(false); // Cache miss
        $this->dbMock->prepare(Argument::any())->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn(false)->shouldBeCalled(); // DB miss
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();

        $this->expectException(ORMException::class);
        $this->expectExceptionMessage('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle Construct Failed');
        
        $obj = new ConcreteDirectSingle($pk);
        $obj->setCanInsertForTest(false); // Ensure _canInsert is false (default)
    }
    
    public function testConstructorDbMissCanInsertTrue()
    {
        $pk = 3;
        $this->redisMock->hGet(Argument::any(), Argument::any())->willReturn(false);
        $this->dbMock->prepare(Argument::any())->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn(false)->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();

        // No exception expected
        $obj = new ConcreteDirectSingle($pk);
        $obj->setCanInsertForTest(true); // Set _canInsert to true
        $this->assertEquals($pk, $obj->id); // PK should still be set
        $this->assertNull($obj->name); // Other fields should be null/default
    }

    // Tests for get/set
    public function testGetAndSet()
    {
        $obj = new ConcreteDirectSingle();
        $this->assertNull($obj->testGet('name'));

        $obj->testSet('name', 'New Name');
        $this->assertEquals('New Name', $obj->testGet('name'));
        $this->assertTrue($obj->_hasChanged());
        $this->assertEquals(['name'], $obj->_getChanged());

        $obj->testSet('email', 'email@example.com');
        $this->assertEquals('email@example.com', $obj->testGet('email'));
        $this->assertEquals(['name', 'email'], $obj->_getChanged());

        // Set to same value should not add to changed if not already changed
        $obj->testSet('is_active', 0); // default is 0, so not changed
        $this->assertNotContains('is_active', $obj->_getChanged());
        $obj->testSet('is_active', 1); // now it's changed
        $this->assertContains('is_active', $obj->_getChanged());
        $obj->testSet('is_active', 1); // set to same changed value
        $this->assertContains('is_active', $obj->_getChanged());
    }

    // More tests will follow for save (INSERT, UPDATE), delete, purgeCache, etc.

    public function testSaveInsertNewObjectCanInsertTrue()
    {
        $obj = new ConcreteDirectSingle();
        $obj->setCanInsertForTest(true);
        $obj->setAuditHandlerForTest(MockAuditHandler::class); // Enable audit for this test

        $obj->testSet('name', 'Insert Name');
        $obj->testSet('email', 'insert@example.com');
        $obj->testSet('is_active', 1);
        // id is null initially

        // DB mock for INSERT
        // The SQL generated by DirectSingle for _canInsert is INSERT ... ON DUPLICATE KEY UPDATE
        // For a new record, it's effectively an INSERT.
        $expectedSqlPattern = '/^INSERT INTO test_table \(.+?\) VALUES \(.+?\) ON DUPLICATE KEY UPDATE name = VALUES\(name\), email = VALUES\(email\), is_active = VALUES\(is_active\), json_data = VALUES\(json_data\)$/s';
        $this->dbMock->prepare(Argument::that(function ($sql) use ($expectedSqlPattern) {
            return preg_match($expectedSqlPattern, trim($sql)) === 1;
        }))->willReturn($this->pdoStatementMock->reveal())->shouldBeCalled();

        $this->pdoStatementMock->execute(Argument::that(function ($params) {
            $this->assertEquals('Insert Name', $params[':name']);
            $this->assertEquals('insert@example.com', $params[':email']);
            $this->assertEquals(1, $params[':is_active']);
            $this->assertEquals('', $params[':id']); // Empty string for null PK on insert with _canInsert
            return true;
        }))->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();
        $this->dbMock->lastInsertId()->willReturn('123')->shouldBeCalled(); // New PK

        // For Audit "before" data (which will be empty as it's a new record)
        // The current save() logic fetches "before" data using the PK.
        // If PK is null/empty (new record), this SELECT might not run or return empty.
        // Let's assume it runs with current PK (null) and returns empty.
        $stmtFetchBefore = $this->prophesize(PDOStatement::class);
        $stmtFetchBefore->execute([':primary' => null])->shouldBeCalled(); // Assuming PK is null before save
        $stmtFetchBefore->fetch(PDO::FETCH_ASSOC)->willReturn([])->shouldBeCalled();
        $stmtFetchBefore->closeCursor()->shouldBeCalled();
        $this->dbMock->prepare("SELECT * FROM test_table WHERE id = :primary")
            ->willReturn($stmtFetchBefore->reveal())->shouldBeCalledTimes(1); // Called for "before"

        // For Audit "after" data
        $stmtFetchAfter = $this->prophesize(PDOStatement::class);
        $stmtFetchAfter->execute([':primary' => 123])->shouldBeCalled(); // PK is now 123
        $stmtFetchAfter->fetch(PDO::FETCH_ASSOC)->willReturn([
            'id' => 123, 'name' => 'Insert Name', 'email' => 'insert@example.com', 'is_active' => 1, 'json_data' => ''
        ])->shouldBeCalled();
        $stmtFetchAfter->closeCursor()->shouldBeCalled();
        // The prepare for "SELECT * FROM test_table WHERE id = :primary" will be called twice.
        $this->dbMock->prepare("SELECT * FROM test_table WHERE id = :primary")
            ->willReturn($stmtFetchAfter->reveal())->shouldBeCalledTimes(1); // Called for "after"


        // Cache interactions
        $this->redisMock->hDel('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_123')->shouldBeCalledOnce(); // Purge by new PK
        $this->redisMock->hSet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_123', Argument::type('string'))->shouldBeCalledOnce(); // Re-cache


        $this->assertTrue($obj->save());
        $this->assertEquals(123, $obj->id); // PK updated
        $this->assertFalse($obj->_hasChanged());
        $this->assertCount(4, $obj->_getLastChanged()); // id, name, email, is_active (id is set from null)
        $this->assertContains('id', $obj->_getLastChanged()); 

        // Check audit log
        $this->assertCount(1, MockAuditHandler::$logs);
        $log = MockAuditHandler::$logs[0];
        $this->assertEquals('test_table', $log['table']);
        $this->assertEquals(123, $log['primaryId']);
        $this->assertEquals([], $log['before']); // Before data is empty for new insert
        $this->assertEquals('Insert Name', $log['after']['name']);
    }

    public function testSaveUpdateExistingObject()
    {
        $pk = 1;
        $initialDbData = ['id' => $pk, 'name' => 'Initial Name', 'email' => 'initial@example.com', 'is_active' => 0, 'json_data' => null];
        
        // Setup for constructor load
        $this->redisMock->hGet(Argument::any(), Argument::any())->willReturn(false); // Cache miss
        $this->dbMock->prepare("SELECT  * FROM test_table WHERE id = :id")
            ->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalledOnce();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn($initialDbData)->shouldBeCalledOnce();
        $this->pdoStatementMock->closeCursor()->shouldBeCalledOnce();
        $this->redisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldBeCalledOnce(); // Initial cache set

        $obj = new ConcreteDirectSingle($pk);
        $obj->setAuditHandlerForTest(MockAuditHandler::class);

        $obj->testSet('name', 'Updated Name');
        $obj->testSet('is_active', 1);

        // DB mock for UPDATE
        $expectedSqlPattern = '/^UPDATE test_table SET name = :name, is_active = :is_active WHERE id = :id$/s';
        $this->dbMock->prepare(Argument::that(function($sql) use ($expectedSqlPattern) {
            return preg_match($expectedSqlPattern, trim($sql)) === 1;
        }))->willReturn($this->pdoStatementMock->reveal())->shouldBeCalled();
        
        $this->pdoStatementMock->execute([
            ':name' => 'Updated Name',
            ':is_active' => 1,
            ':id' => $pk
        ])->shouldBeCalled();
        // closeCursor for update is already mocked from constructor load, need to allow multiple calls or re-prophesize
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();


        // For Audit "before" data (this is $initialDbData)
        // The prepare for "SELECT * FROM test_table WHERE id = :primary" will be called again for audit.
        $stmtFetchBefore = $this->prophesize(PDOStatement::class);
        $stmtFetchBefore->execute([':primary' => $pk])->shouldBeCalled();
        $stmtFetchBefore->fetch(PDO::FETCH_ASSOC)->willReturn($initialDbData)->shouldBeCalled();
        $stmtFetchBefore->closeCursor()->shouldBeCalled();
        $this->dbMock->prepare("SELECT * FROM test_table WHERE id = :primary")
            ->willReturn($stmtFetchBefore->reveal())->shouldBeCalledTimes(1);


        // For Audit "after" data
        $finalDbData = ['id' => $pk, 'name' => 'Updated Name', 'email' => 'initial@example.com', 'is_active' => 1, 'json_data' => null];
        $stmtFetchAfter = $this->prophesize(PDOStatement::class);
        $stmtFetchAfter->execute([':primary' => $pk])->shouldBeCalled();
        $stmtFetchAfter->fetch(PDO::FETCH_ASSOC)->willReturn($finalDbData)->shouldBeCalled();
        $stmtFetchAfter->closeCursor()->shouldBeCalled();
        $this->dbMock->prepare("SELECT * FROM test_table WHERE id = :primary")
            ->willReturn($stmtFetchAfter->reveal())->shouldBeCalledTimes(1);


        // Cache interactions for save
        $this->redisMock->hDel('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)->shouldBeCalledOnce();
        $this->redisMock->hSet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk, Argument::type('string'))->shouldBeCalledOnce();

        $this->assertTrue($obj->save());
        $this->assertFalse($obj->_hasChanged());
        $this->assertEquals(['name', 'is_active'], $obj->_getLastChanged());

        // Check audit log
        $this->assertCount(1, MockAuditHandler::$logs);
        $log = MockAuditHandler::$logs[0];
        $this->assertEquals($pk, $log['primaryId']);
        $this->assertEquals('Initial Name', $log['before']['name']);
        $this->assertEquals('Updated Name', $log['after']['name']);
    }

    public function testSaveNoChanges()
    {
        $pk = 1;
        // Load object
        $this->redisMock->hGet(Argument::any(), Argument::any())->willReturn(false);
        $this->dbMock->prepare(Argument::any())->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalledOnce();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn(['id' => $pk, 'name' => 'Test'])->shouldBeCalledOnce();
        $this->pdoStatementMock->closeCursor()->shouldBeCalledOnce(); // For load
        $this->redisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldBeCalledOnce(); // For load cache set


        $obj = new ConcreteDirectSingle($pk);
        
        // DB prepare/execute for UPDATE should NOT be called
        $this->dbMock->prepare(Argument::containingString('UPDATE'))->shouldNotBeCalled();
        // Audit related fetches should also not be called if no changes
        $this->dbMock->prepare(Argument::containingString('SELECT * FROM test_table WHERE id = :primary'))->shouldNotBeCalled();


        $this->assertFalse($obj->save()); // No changes, save returns false
        $this->assertFalse($obj->_hasChanged());
    }

    public function testPurgeCacheWithRecache()
    {
        $pk = 1;
        $obj = new ConcreteDirectSingle($pk); // Assumes it might load and cache
        $obj->setCanCacheForTest(true);
        $obj->setCanCacheAfterPurgeForTest(true);

        // Expect hDel for purge
        $this->redisMock->hDel('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)
            ->shouldBeCalledOnce();

        // Expect hSet for re-caching via setCacheData
        // setCacheData takes current object state and serializes it.
        $this->redisMock->hSet('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk, Argument::that(function($serializedData) use ($obj) {
            $data = unserialize($serializedData);
            $this->assertEquals($obj->id, $data['id']); // Check if some data matches current obj state
            $this->assertArrayHasKey('ORM_TIME', $data);
            return true;
        }))->shouldBeCalledOnce();

        $obj->purgeCache();
    }

    public function testPurgeCacheWithoutRecache()
    {
        $pk = 1;
        $obj = new ConcreteDirectSingle($pk);
        $obj->setCanCacheForTest(true);
        $obj->setCanCacheAfterPurgeForTest(false); // Do not re-cache

        $this->redisMock->hDel('GCWorld\ORM\Tests\Abstracts\ConcreteDirectSingle', 'key_'.$pk)
            ->shouldBeCalledOnce();
        
        // hSet should NOT be called
        $this->redisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();

        $obj->purgeCache();
    }
    
    public function testPurgeCacheWhenCachingIsDisabled()
    {
        $pk = 1;
        $obj = new ConcreteDirectSingle($pk);
        $obj->setCanCacheForTest(false); // Caching disabled

        // hDel should NOT be called
        $this->redisMock->hDel(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->redisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();

        $obj->purgeCache();
    }

    public function testGetFieldKeys()
    {
        $obj = new ConcreteDirectSingle();
        $expectedKeys = ['id', 'name', 'email', 'is_active', 'json_data'];
        $this->assertEqualsCanonicalizing($expectedKeys, $obj->getFieldKeys());
    }

    public function testConstructorLoadingWhenCanCacheIsFalse()
    {
        $pk = 1;
        $dbData = ['id' => $pk, 'name' => 'DB Name', 'email' => 'db@example.com', 'is_active' => 1, 'json_data' => null];
        
        // Redis should not be touched at all
        $this->redisMock->hGet(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->redisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();

        $this->dbMock->prepare("SELECT  * FROM test_table WHERE id = :id")
            ->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn($dbData)->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();
        
        $obj = new ConcreteDirectSingle($pk);
        $obj->setCanCacheForTest(false); // Disable caching AFTER common/cache is set but before load logic
                                         // Better: pass a common that returns null for cache, or set _canCache in Concrete class.
                                         // For now, this tests that if _canCache is false, constructor path avoids cache.
                                         // This requires _canCache to be checked before hGet.
                                         // The constructor's logic: $cachable = $this->_canCache && !empty($this->_cache) ...
                                         // So, if _canCache is false, $cachable will be false.

        // Re-instantiate to test constructor path properly with _canCache already false
        // Need a way to set _canCache on the *class* before instance or pass it in.
        // Let's use a new concrete class for this.
        $newCommonMock = $this->prophesize(CommonInterface::class);
        $newDbMock = $this->prophesize(DatabaseInterface::class);
        $newRedisMock = $this->prophesize(Redis::class); // Still provide it, but _canCache will be false.
        $newCommonMock->getDatabase(Argument::any())->willReturn($newDbMock->reveal());
        $newCommonMock->getCache(Argument::any())->willReturn($newRedisMock->reveal());
        $newCommonMock->getConfig('audit')->willReturn(['prefix' => '_Audit_', 'enable' => true]);
        CommonLoader::setCommonObject($newCommonMock->reveal());

        $newPdoStmtMock = $this->prophesize(PDOStatement::class);
        $newDbMock->prepare(Argument::any())->willReturn($newPdoStmtMock->reveal());
        $newPdoStmtMock->execute([':id' => $pk])->shouldBeCalled();
        $newPdoStmtMock->fetch(PDO::FETCH_ASSOC)->willReturn($dbData)->shouldBeCalled();
        $newPdoStmtMock->closeCursor()->shouldBeCalled();


        // Concrete class that has _canCache = false by default
        $testObj = new class($pk) extends ConcreteDirectSingle { // Anonymous class
            protected bool $_canCache = false;
            public function __construct($pkVal) { parent::__construct($pkVal); }
        };
        
        $this->assertEquals('DB Name', $testObj->name);
        // Assert Redis was not called by the new Common object's Redis mock
        $newRedisMock->hGet(Argument::any(), Argument::any())->shouldNotBeCalled();
        $newRedisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();
    }

    public function testSaveAuditDisabled()
    {
        $pk = 1;
        $obj = new ConcreteDirectSingle($pk); // Assume loaded
        $obj->setAuditForTest(false); // Disable audit
        $obj->setAuditHandlerForTest(MockAuditHandler::class); // Provide handler, but it shouldn't be called

        $obj->testSet('name', 'NoAudit Update');

        // DB mock for UPDATE (should still happen)
        $this->dbMock->prepare(Argument::containingString('UPDATE test_table SET name = :name WHERE id = :id'))
            ->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':name' => 'NoAudit Update', ':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();

        // Audit related DB calls for before/after data should NOT happen
        // $this->dbMock->prepare("SELECT * FROM test_table WHERE id = :primary") -> shouldNotBeCalled();
        // Prophecy doesn't easily support "shouldNotBeCalled" if it was called in setUp for loading.
        // Instead, we check MockAuditHandler::$logs.

        // Cache interactions
        $this->redisMock->hDel(Argument::any(), Argument::any())->shouldBeCalled();
        $this->redisMock->hSet(Argument::any(), Argument::any(), Argument::any())->shouldBeCalled();


        $this->assertTrue($obj->save());
        $this->assertCount(0, MockAuditHandler::$logs, "Audit logs should be empty if _audit is false.");
    }
}

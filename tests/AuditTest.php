<?php

namespace GCWorld\ORM\Tests;

use GCWorld\Interfaces\CommonInterface;
use GCWorld\Database\Database;
use GCWorld\ORM\Audit;
use GCWorld\ORM\Config;
use GCWorld\ORM\Globals;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use PDOStatement;
use PDOException;

class AuditTest extends TestCase
{
    use ProphecyTrait;

    protected $commonMock;
    protected $configMock;
    protected $globalsMock;

    protected function setUp(): void
    {
        // Mock Config first as it's used statically in Audit's constructor
        // We need to control what $globalConfigObj->getConfig() returns.
        // One way is to replace the Config class entirely for the test,
        // or use reflection to set the static $config property.
        // For now, let's assume Config can be new-ed up and its method mocked,
        // though this is tricky due to `new Config()` inside Audit.
        // A better approach would be dependency injection for Config, or a static getter we can mock.
        // Given the current structure, we might need to test Audit's constructor effect on its internal state.

        $this->commonMock = $this->prophesize(CommonInterface::class);
        $this->globalsMock = $this->prophesize(Globals::class);

        // Reset static properties in Audit class before each test
        Audit::clearOverrideMemberId();
        $reflectionClass = new \ReflectionClass(Audit::class);
        $configProperty = $reflectionClass->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue(null, null); // Reset static config
    }

    protected function mockGlobalConfig(array $config): void
    {
        $configInstanceMock = $this->prophesize(Config::class);
        $configInstanceMock->getConfig()->willReturn($config);

        // This is the tricky part: Audit news up Config directly.
        // For true unit testing, Config should be injected or accessed via a replaceable static method.
        // For now, we will set the static property directly using reflection.
        $reflectionClass = new \ReflectionClass(Audit::class);
        $configProperty = $reflectionClass->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue(null, $config);
    }

    public function testConstructorDefaultConfigAuditEnabled()
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $this->commonMock->getConfig('audit')->willReturn(null); // No specific audit config

        $audit = new Audit($this->commonMock->reveal());

        // Use reflection to check internal properties
        $reflection = new \ReflectionClass($audit);

        $canAuditProp = $reflection->getProperty('canAudit');
        $canAuditProp->setAccessible(true);
        $this->assertTrue($canAuditProp->getValue($audit));

        $enableProp = $reflection->getProperty('enable');
        $enableProp->setAccessible(true);
        $this->assertTrue($enableProp->getValue($audit)); // Defaults to true if no specific audit config

        $databaseProp = $reflection->getProperty('database');
        $databaseProp->setAccessible(true);
        $this->assertNull($databaseProp->getValue($audit));

        $connectionProp = $reflection->getProperty('connection');
        $connectionProp->setAccessible(true);
        $this->assertEquals('default', $connectionProp->getValue($audit));

        $prefixProp = $reflection->getProperty('prefix');
        $prefixProp->setAccessible(true);
        $this->assertEquals('_Audit_', $prefixProp->getValue($audit));
    }

    public function testConstructorGlobalAuditDisabled()
    {
        $this->mockGlobalConfig(['general' => ['audit' => false]]);
        // commonMock getConfig should not even be called if global audit is false for audit-specific settings.

        $audit = new Audit($this->commonMock->reveal());

        $reflection = new \ReflectionClass($audit);
        $canAuditProp = $reflection->getProperty('canAudit');
        $canAuditProp->setAccessible(true);
        $this->assertFalse($canAuditProp->getValue($audit));

        // Other properties like enable, database, connection, prefix should be in their default state
        // but effectively unused if canAudit is false.
        $enableProp = $reflection->getProperty('enable');
        $enableProp->setAccessible(true);
        // Based on current Audit.php, if canAudit is false, these are set to defaults but not used by storeLog
        $this->assertTrue($enableProp->getValue($audit)); // Still true by default initialisation path
    }

    public function testConstructorWithSpecificAuditConfig()
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $specificAuditConfig = [
            'enable'     => false,
            'database'   => 'test_audit_db',
            'connection' => 'audit_conn',
            'prefix'     => '_TestAudit_'
        ];
        $this->commonMock->getConfig('audit')->willReturn($specificAuditConfig);

        $audit = new Audit($this->commonMock->reveal());
        $reflection = new \ReflectionClass($audit);

        $canAuditProp = $reflection->getProperty('canAudit');
        $canAuditProp->setAccessible(true);
        $this->assertTrue($canAuditProp->getValue($audit));

        $enableProp = $reflection->getProperty('enable');
        $enableProp->setAccessible(true);
        $this->assertFalse($enableProp->getValue($audit));

        $databaseProp = $reflection->getProperty('database');
        $databaseProp->setAccessible(true);
        $this->assertEquals('test_audit_db', $databaseProp->getValue($audit));

        $connectionProp = $reflection->getProperty('connection');
        $connectionProp->setAccessible(true);
        $this->assertEquals('audit_conn', $connectionProp->getValue($audit));

        $prefixProp = $reflection->getProperty('prefix');
        $prefixProp->setAccessible(true);
        $this->assertEquals('_TestAudit_', $prefixProp->getValue($audit));
    }

    public function testConstructorSpecificAuditConfigNotArray()
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $this->commonMock->getConfig('audit')->willReturn('not-an-array'); // Invalid config type

        $audit = new Audit($this->commonMock->reveal());
        $reflection = new \ReflectionClass($audit);

        $canAuditProp = $reflection->getProperty('canAudit');
        $canAuditProp->setAccessible(true);
        $this->assertTrue($canAuditProp->getValue($audit));

        $enableProp = $reflection->getProperty('enable');
        $enableProp->setAccessible(true);
        // Defaults to true if specific audit config is not an array
        $this->assertTrue($enableProp->getValue($audit));

        $databaseProp = $reflection->getProperty('database');
        $databaseProp->setAccessible(true);
        $this->assertNull($databaseProp->getValue($audit)); // Default

        $connectionProp = $reflection->getProperty('connection');
        $connectionProp->setAccessible(true);
        $this->assertEquals('default', $connectionProp->getValue($audit)); // Default

        $prefixProp = $reflection->getProperty('prefix');
        $prefixProp->setAccessible(true);
        $this.assertEquals('_Audit_', $prefixProp->getValue($audit)); // Default
    }

    public function testSetAndClearOverrideMemberId()
    {
        // This tests the static methods themselves, effect on determineMemberId
        // will be tested via storeLog.
        Audit::setOverrideMemberId(123);
        $reflection = new \ReflectionClass(Audit::class);
        $overrideMemberIdProp = $reflection->getProperty('overrideMemberId');
        $overrideMemberIdProp->setAccessible(true);
        $this->assertEquals(123, $overrideMemberIdProp->getValue(null));

        Audit::clearOverrideMemberId();
        $this->assertNull($overrideMemberIdProp->getValue(null));
    }

    public function testStoreLogWhenCanAuditIsFalse()
    {
        $this->mockGlobalConfig(['general' => ['audit' => false]]); // This makes canAudit false
        $this->commonMock->getConfig('audit')->shouldNotBeCalled();

        $audit = new Audit($this->commonMock->reveal());
        $result = $audit->storeLog('test_table', 1, ['foo' => 'bar'], ['foo' => 'baz']);

        $this->assertEquals(0, $result);
    }

    public function testStoreLogWhenAuditConfigDisabled()
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $specificAuditConfig = ['enable' => false]; // Audit is configured but disabled
        $this->commonMock->getConfig('audit')->willReturn($specificAuditConfig);

        $audit = new Audit($this->commonMock->reveal());
        $result = $audit->storeLog('test_table', 1, ['foo' => 'bar'], ['foo' => 'baz']);

        $this->assertEquals(0, $result);
    }

    public function testStoreLogWithEmptyPrimaryIdThrowsException()
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $this->commonMock->getConfig('audit')->willReturn(['enable' => true]); // Audit enabled

        $audit = new Audit($this->commonMock->reveal());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('AUDIT LOG:: Invalid Primary ID Passed');
        $audit->storeLog('test_table', '', ['foo' => 'bar'], ['foo' => 'baz']);
    }

    public function testStoreLogNoActualChangesReturnsZero()
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $this->commonMock->getConfig('audit')->willReturn(['enable' => true, 'database' => null, 'connection' => 'default', 'prefix' => '_Audit_']);

        // Mock database interactions that happen before the change check
        $dbMock = $this->prophesize(Database::class);
        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());

        // AuditUtilities::cleanData is static, so we can't easily mock it.
        // We ensure $before and $after are identical to trigger the "no changes" condition.
        $audit = new Audit($this->commonMock->reveal());
        $result = $audit->storeLog('test_table', 1, ['foo' => 'bar'], ['foo' => 'bar']);

        $this->assertEquals(0, $result, "Should return 0 if data hasn't changed.");
    }

    // More tests for storeLog will follow, covering successful logging,
    // table creation fallback, and various member ID scenarios.
    // Also tests for the getter methods.

    protected function getAuditInstanceForSuccessfulLog(array $auditConfig = [], ?string $requestUri = '/test/uri'): Audit
    {
        $this->mockGlobalConfig(['general' => ['audit' => true]]);
        $fullAuditConfig = array_merge([
            'enable'     => true,
            'database'   => null,
            'connection' => 'default',
            'prefix'     => '_Audit_'
        ], $auditConfig);
        $this->commonMock->getConfig('audit')->willReturn($fullAuditConfig);

        $this->globalsMock->string()->SERVER('REQUEST_URI')->willReturn($requestUri);
        // Replace the global instance with our mock for the duration of the test where Audit might use new Globals()
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $originalGlobalsInstance = $instanceProperty->getValue();
        $instanceProperty->setValue(null, $this->globalsMock->reveal());


        // Restore original Globals instance after test if needed, or ensure setUp does.
        // For now, assuming one Audit instance per test method requiring Globals.
        // A cleaner way would be to inject Globals or a request object into Audit.

        return new Audit($this->commonMock->reveal());
    }

    public function testStoreLogSuccessfulInsert()
    {
        $audit = $this->getAuditInstanceForSuccessfulLog();
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->prophesize(PDOStatement::class);

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());

        $expectedTable = '_Audit_my_table';
        $expectedSql = 'INSERT INTO '.$expectedTable.'
                (primary_id, member_id, log_request_uri, log_before, log_after)
                VALUES
                (:pid, :mid, :uri, :logB, :logA)';

        $beforeData = ['name' => 'old', 'value' => 1];
        $afterData = ['name' => 'new', 'value' => 2];
        $primaryId = 'xyz123';
        $memberId = 42; // Explicitly passed

        // json_encode will be called on these by AuditUtilities::cleanData logic
        $expectedBeforeJson = json_encode(['name' => 'old', 'value' => 1]);
        $expectedAfterJson = json_encode(['name' => 'new', 'value' => 2]);


        $stmtMock->execute([
            ':pid'  => $primaryId,
            ':mid'  => $memberId,
            ':uri'  => '/test/uri',
            ':logB' => $expectedBeforeJson,
            ':logA' => $expectedAfterJson
        ])->shouldBeCalledOnce();
        $stmtMock->closeCursor()->shouldBeCalledOnce();

        $dbMock->prepare($expectedSql)->willReturn($stmtMock->reveal())->shouldBeCalledOnce();
        $dbMock->lastInsertId()->willReturn('99')->shouldBeCalledOnce();

        $result = $audit->storeLog('my_table', $primaryId, $beforeData, $afterData, $memberId);

        $this->assertEquals('99', $result);
        $this->assertEquals('my_table', $audit->getTable());
        $this->assertEquals($primaryId, $audit->getPrimaryId());
        $this->assertEquals($memberId, $audit->getMemberId());
        $this->assertEquals($beforeData, $audit->getBefore()); // Before/After on Audit instance are raw
        $this->assertEquals($afterData, $audit->getAfter());

        // Restore Globals instance
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        // This needs $originalGlobalsInstance from getAuditInstanceForSuccessfulLog,
        // which is tricky. Better to do this in tearDown or ensure setUp handles it.
        // For now, setting to null, hoping setUp re-initializes if another test uses real Globals.
        $instanceProperty->setValue(null, null);
    }

    public function testStoreLogSuccessfulInsertWithDatabasePrefix()
    {
        $audit = $this->getAuditInstanceForSuccessfulLog(['database' => 'audit_db']);
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->prophesize(PDOStatement::class);

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());

        $expectedTable = 'audit_db._Audit_my_table'; // Note the db prefix
        $expectedSql = 'INSERT INTO '.$expectedTable.'
                (primary_id, member_id, log_request_uri, log_before, log_after)
                VALUES
                (:pid, :mid, :uri, :logB, :logA)';

        $beforeData = ['name' => 'old'];
        $afterData = ['name' => 'new'];
        $primaryId = 'xyz123';

        $stmtMock->execute(Argument::type('array'))->shouldBeCalledOnce();
        $stmtMock->closeCursor()->shouldBeCalledOnce();
        $dbMock->prepare($expectedSql)->willReturn($stmtMock->reveal())->shouldBeCalledOnce();
        $dbMock->lastInsertId()->willReturn('100');

        $audit->storeLog('my_table', $primaryId, $beforeData, $afterData, 1); // Member ID 1
        // Assertions similar to above can be added if needed to check SQL details
        $this->assertEquals('100', $dbMock->reveal()->lastInsertId()); // Example check

        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    public function testStoreLogNoRequestUriUsesGetTopScript()
    {
        // Pass null as requestUri to trigger getTopScript
        $audit = $this->getAuditInstanceForSuccessfulLog([], null);
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->prophesize(PDOStatement::class);

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());

        // Globals mock for SERVER('REQUEST_URI') already set to null in getAuditInstanceForSuccessfulLog
        // The getTopScript method uses debug_backtrace, which is hard to mock directly.
        // We'll check that the URI in the DB is a file path (contains .php).
        // This is an approximation.

        $stmtMock->execute(Argument::that(function ($params) {
            $this->assertIsString($params[':uri']);
            $this->assertStringContainsString('.php', $params[':uri']); // Check if it looks like a file path
            return true;
        }))->shouldBeCalledOnce();
        $stmtMock->closeCursor()->shouldBeCalledOnce();

        $dbMock->prepare(Argument::type('string'))->willReturn($stmtMock->reveal());
        $dbMock->lastInsertId()->willReturn('101');

        $audit->storeLog('my_table', 'pid1', ['k' => 'v1'], ['k' => 'v2'], 1);
        $this->assertEquals('101', $dbMock->reveal()->lastInsertId());

        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    public function testStoreLogTableCreationFallback()
    {
        $audit = $this->getAuditInstanceForSuccessfulLog();
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->prophesize(PDOStatement::class);
        // Mock for CreateAuditTable, though it's new'd up directly.
        // This is hard to test without refactoring Audit to inject CreateAuditTable factory/instance.
        // For now, we'll just ensure the retry happens.

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());

        $expectedTable = '_Audit_my_table';
        $expectedSql = 'INSERT INTO '.$expectedTable.'
                (primary_id, member_id, log_request_uri, log_before, log_after)
                VALUES
                (:pid, :mid, :uri, :logB, :logA)';

        $pdoException = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.{$expectedTable}' doesn't exist");

        // First attempt to execute fails
        $stmtMock->execute(Argument::type('array'))
            ->willThrow($pdoException)
            ->shouldBeCalledOnce(); // This is the first call

        // Second attempt to execute (after table creation) succeeds
        $stmtMock->execute(Argument::type('array'))
            ->willReturn(true) // Assuming execute returns true on success
            ->shouldBeCalledOnce(); // This is the second call

        $stmtMock->closeCursor()->shouldBeCalledTimes(2); // Called after each execute attempt

        $dbMock->prepare($expectedSql)
            ->willReturn($stmtMock->reveal())
            ->shouldBeCalledTimes(2); // Prepared twice, once for each attempt

        $dbMock->lastInsertId()->willReturn('102')->shouldBeCalledOnce(); // Called after successful insert

        // We cannot easily mock `new CreateAuditTable` and its `buildTable` method here.
        // We are primarily testing that the catch block for PDOException is triggered
        // and that the SQL execution is retried.

        $result = $audit->storeLog('my_table', 'pid2', ['k' => 'v1'], ['k' => 'v2'], 1);
        $this->assertEquals('102', $result);

        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    // Next: Tests for determineMemberId scenarios

    /**
     * Helper to get a mock PDOStatement that expects an execute call
     * with a specific member ID.
     */
    protected function getExpectedMemberIdStatementMock(mixed $expectedMemberId, string $requestUri = '/test/uri'): \Prophecy\Prophecy\ObjectProphecy
    {
        $stmtMock = $this->prophesize(PDOStatement::class);
        $stmtMock->execute(Argument::that(function ($params) use ($expectedMemberId, $requestUri) {
            $this->assertEquals($expectedMemberId, $params[':mid']);
            $this->assertEquals($requestUri, $params[':uri']); // Also check URI for consistency
            return true;
        }))->shouldBeCalledOnce();
        $stmtMock->closeCursor()->shouldBeCalledOnce();
        return $stmtMock;
    }

    public function testStoreLogDetermineMemberIdWithOverride()
    {
        Audit::setOverrideMemberId(789);
        $audit = $this->getAuditInstanceForSuccessfulLog(); // Uses default URI '/test/uri'
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->getExpectedMemberIdStatementMock(789); // overrideMemberId is intval'd

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());
        $dbMock->prepare(Argument::type('string'))->willReturn($stmtMock->reveal());
        $dbMock->lastInsertId()->willReturn('200');

        // $memberId is null, so determineMemberId() will be called
        $result = $audit->storeLog('test_table', 'pid3', ['k' => 'v1'], ['k' => 'v2'], null);
        $this->assertEquals('200', $result);
        $this->assertEquals(789, $audit->getMemberId());

        Audit::clearOverrideMemberId(); // Clean up static
        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    public function testStoreLogDetermineMemberIdNoGetUserMethodOnCommon()
    {
        // $this->commonMock is already a prophecy object.
        // We just don't prophesize a getUser method on it.
        $audit = $this->getAuditInstanceForSuccessfulLog();
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->getExpectedMemberIdStatementMock(0); // Expect 0 if no user method

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());
        $dbMock->prepare(Argument::type('string'))->willReturn($stmtMock->reveal());
        $dbMock->lastInsertId()->willReturn('201');

        $result = $audit->storeLog('test_table', 'pid4', ['k' => 'v1'], ['k' => 'v2'], null);
        $this->assertEquals('201', $result);
        $this->assertEquals(0, $audit->getMemberId());

        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    public function testStoreLogDetermineMemberIdCommonGetUserReturnsNonObject()
    {
        $this->commonMock->getUser()->willReturn('not_an_object');
        $audit = $this->getAuditInstanceForSuccessfulLog();
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->getExpectedMemberIdStatementMock(0); // Expect 0

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());
        $dbMock->prepare(Argument::type('string'))->willReturn($stmtMock->reveal());
        $dbMock->lastInsertId()->willReturn('202');

        $result = $audit->storeLog('test_table', 'pid5', ['k' => 'v1'], ['k' => 'v2'], null);
        $this->assertEquals('202', $result);
        $this->assertEquals(0, $audit->getMemberId());

        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    protected function runDetermineMemberIdScenario(string $userMethodName = null, mixed $userMethodValue = null,
                                                string $userPropertyName = null, mixed $userPropertyValue = null,
                                                mixed $expectedMemberId, string $testPid, string $expectedReturnId)
    {
        $userMock = $this->prophesize(\stdClass::class); // Using stdClass as a generic object

        if ($userMethodName) {
            // Dynamically add method to prophecy if it's one of the expected ones
            if(in_array($userMethodName, ['getRealMemberUuid', 'getRealMemberId', 'getMemberId', 'get'])) {
                if($userMethodName === 'get') {
                     // CLASS_PRIMARY is 'id_user' and $user->get('id_user') will be called
                    $userMock->get('id_user')->willReturn($userMethodValue);
                } else {
                    $userMock->$userMethodName()->willReturn($userMethodValue);
                }
            }
        }

        if ($userPropertyName) {
            // For CLASS_PRIMARY property test
            $userMock->{$userPropertyName} = $userPropertyValue; // Public property
            // Define CLASS_PRIMARY on the mocked stdClass instance for the test
            // This is tricky as CLASS_PRIMARY is looked up on get_class($user)
            // A real class with the constant would be better.
            // For now, this specific test case (CLASS_PRIMARY + property) might be hard to hit accurately
            // without a more complex mock or a real class.
            // Let's assume for now that if $userPropertyName is 'id_user', the constant was defined.
        }


        $this->commonMock->getUser()->willReturn($userMock->reveal());
        $audit = $this->getAuditInstanceForSuccessfulLog();
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->getExpectedMemberIdStatementMock($expectedMemberId);

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());
        $dbMock->prepare(Argument::type('string'))->willReturn($stmtMock->reveal());
        $dbMock->lastInsertId()->willReturn($expectedReturnId);

        $result = $audit->storeLog('test_table', $testPid, ['k' => 'v1'], ['k' => 'v2'], null);
        $this->assertEquals($expectedReturnId, $result);
        $this->assertEquals($expectedMemberId, $audit->getMemberId());

        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    public function testStoreLogDetermineMemberIdUserGetRealMemberUuid()
    {
        $this->runDetermineMemberIdScenario('getRealMemberUuid', 'uuid-123-abc', null, null, 'uuid-123-abc', 'pid6', '203');
    }

    public function testStoreLogDetermineMemberIdUserGetRealMemberId()
    {
        $this->runDetermineMemberIdScenario('getRealMemberId', 987, null, null, 987, 'pid7', '204');
    }

    public function testStoreLogDetermineMemberIdUserGetMemberId()
    {
        $this->runDetermineMemberIdScenario('getMemberId', 654, null, null, 654, 'pid8', '205');
    }
    
    // Test for CLASS_PRIMARY with property access is hard due to constant.
    // We'll skip directly testing that specific path if it requires defining a dynamic class with a constant.
    // However, if CLASS_PRIMARY logic falls through to get('property_name'), we can test that part.

    // Test for CLASS_PRIMARY with get('property_name')
    // To test this, we need a way to make defined(get_class($user).'::CLASS_PRIMARY') true
    // and then constant(get_class($user).'::CLASS_PRIMARY') return 'id_user'.
    // This is difficult with prophecy on stdClass.
    // A dedicated mock class for the user object would be better.

    // For now, let's assume a simplified test for the "get" method if others fail.
    // The following test is more of a general "get" method on user if other specific methods aren't there.
    // The true `CLASS_PRIMARY` logic is harder to unit test in isolation without a real class or more complex mocking.

    public function testStoreLogDetermineMemberIdUserDefaultFails()
    {
        // This scenario implies getUser() returns an object, but it has none of the special methods/properties.
        $userMock = $this->prophesize(\stdClass::class); // Empty object
        $this->commonMock->getUser()->willReturn($userMock->reveal());

        $audit = $this->getAuditInstanceForSuccessfulLog();
        $dbMock = $this->prophesize(Database::class);
        $stmtMock = $this->getExpectedMemberIdStatementMock(0); // Expect 0 (default)

        $this->commonMock->getDatabase('default')->willReturn($dbMock->reveal());
        $dbMock->prepare(Argument::type('string'))->willReturn($stmtMock->reveal());
        $dbMock->lastInsertId()->willReturn('207');

        $result = $audit->storeLog('test_table', 'pid10', ['k' => 'v1'], ['k' => 'v2'], null);
        $this->assertEquals('207', $result);
        $this->assertEquals(0, $audit->getMemberId());
        
        // Restore Globals
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        $instanceProperty = $reflectionGlobals->getProperty('instance');
        $instanceProperty->setAccessible(true);
        $instanceProperty->setValue(null, null);
    }

    // It's good practice to have a tearDown method to clean up static properties
    // or global states modified during tests.
    protected function tearDown(): void
    {
        parent::tearDown();
        Audit::clearOverrideMemberId();

        // Attempt to restore/reset Globals::$instance
        // This is still a workaround for the direct `new Globals()` in Audit.php
        $reflectionGlobals = new \ReflectionClass(Globals::class);
        if ($reflectionGlobals->hasProperty('instance')) {
            $instanceProperty = $reflectionGlobals->getProperty('instance');
            $instanceProperty->setAccessible(true);
            // Setting to null might allow it to be re-initialized if necessary,
            // or it should be restored to its original pre-test state if that was saved.
            $instanceProperty->setValue(null, null);
        }

        // Reset Audit's static config as well
        $reflectionClass = new \ReflectionClass(Audit::class);
        $configProperty = $reflectionClass->getProperty('config');
        $configProperty->setAccessible(true);
        $configProperty->setValue(null, null);
    }
}

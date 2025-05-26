<?php

namespace GCWorld\ORM\Tests\Core;

use GCWorld\ORM\Core;
use GCWorld\ORM\Config;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use Monolog\Logger;
use Monolog\Handler\TestHandler; // To capture log messages
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Symfony\Component\Yaml\Yaml;
use PDO;
use PDOStatement;

class CoreTest extends TestCase
{
    use ProphecyTrait;

    private vfsStreamDirectory $root;
    private string $vfsPath;

    private string $realPackageConfigDir;
    private string $realPackagePointerConfigYmlPath; // Pointer for Config class

    private $commonMock;
    private $dbMock;
    private TestHandler $logHandler;


    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->vfsPath = vfsStream::url('root');

        // Real path for package's config/config.yml (pointer file for Config class)
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

        // Mocks
        $this->commonMock = $this->prophesize(CommonInterface::class);
        $this->dbMock = $this->prophesize(DatabaseInterface::class);
        $this->commonMock->getDatabase()->willReturn($this->dbMock->reveal());

        // Logger setup
        $this->logHandler = new TestHandler();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->realPackagePointerConfigYmlPath)) {
            unlink($this->realPackagePointerConfigYmlPath);
        }
        if (is_dir($this->realPackageConfigDir) && count(scandir($this->realPackageConfigDir)) <= 2) {
            rmdir($this->realPackageConfigDir);
        }
    }

    /**
     * Helper to create the real config.yml pointer file and the VFS user config.
     */
    private function setupUserConfig(array $userConfigData): void
    {
        $userConfigVfsPath = $this->vfsPath . '/test_user_orm_config.yml';
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));

        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()), // real src dir of Config.php
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

    public function testConstructorInitializesPropertiesFromConfig()
    {
        $namespace = 'Test\\App\\GeneratedORM';
        $userConfig = [
            'version' => Config::VERSION,
            'general' => [
                'common' => 'SomeCommon', 'user' => 'SomeUser', 'audit' => false,
                'audit_handler' => 'MyAuditHandler' // This will be overridden by default if audit is false
            ],
            'options' => [
                'var_visibility' => 'protected',
                'get_set_funcs' => false,
                'json_serialize' => false,
                'use_defaults' => false,
                'defaults_override_null' => true, // Example: different from default
                'type_hinting' => true,
            ]
        ];
        $this->setupUserConfig($userConfig);

        $core = new Core($namespace, $this->commonMock->reveal());

        // Use reflection to check readonly properties
        $reflection = new \ReflectionClass(Core::class);

        $this->assertEquals($namespace, $reflection->getProperty('master_namespace')->getValue($core));
        $this->assertSame($this->commonMock->reveal(), $reflection->getProperty('master_common')->getValue($core));
        
        // Check config-derived properties
        $this->assertFalse($reflection->getProperty('audit')->getValue($core)); // general.audit is false
        $this->assertEquals('protected', $reflection->getProperty('var_visibility')->getValue($core));
        $this->assertFalse($reflection->getProperty('get_set_funcs')->getValue($core));
        $this->assertFalse($reflection->getProperty('json_serialize')->getValue($core));
        $this->assertFalse($reflection->getProperty('use_defaults')->getValue($core));
        $this->assertTrue($reflection->getProperty('defaults_override_null')->getValue($core));
        $this->assertTrue($reflection->getProperty('type_hinting')->getValue($core));
        $this->assertInstanceOf(Logger::class, $core->getLogger());
    }

    public function testSetAndGetLogger()
    {
        $this->setupUserConfig(['general' => ['common'=>'C','user'=>'U']]); // Minimal config
        $core = new Core('N', $this->commonMock->reveal());
        
        $loggerName = 'MyCustomLogger';
        $customLogger = new Logger($loggerName);
        $customLogger->pushHandler($this->logHandler);

        $core->setLogger($customLogger);
        $this->assertSame($customLogger, $core->getLogger());

        $core->getLogger()->info('Test message');
        $this->assertTrue($this->logHandler->hasInfoRecords());
        $this->assertTrue($this->logHandler->hasInfoThatContains('Test message'));
    }

    // Basic generate test: table with one PK, one field.
    // This will be expanded greatly.
    public function testGenerateBasicTable()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'example_table';
        $userConfig = [
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U', 'audit_handler' => 'DefaultAudit'],
            'options' => ['var_visibility' => 'public', 'get_set_funcs' => true, 'json_serialize' => true, 'use_defaults' => true, 'type_hinting' => true],
            'tables'  => [
                $tableName => [ // Table specific config
                    'constructor' => 'public',
                ]
            ]
        ];
        $this->setupUserConfig($userConfig);

        // Mock database schema for 'example_table'
        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => 'Primary Key'],
            ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => 'User Name'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());

        // Mock for getKeys (used for factory methods)
        $stmtKeys = $this->prophesize(PDOStatement::class);
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1]
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        
        // Override master_location to point to VFS for generated files
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        $vfsGeneratedPathRoot = $this->vfsPath . '/orm_generated_output';
        mkdir($vfsGeneratedPathRoot); // Ensure base output dir exists in VFS
        $masterLocationProp->setValue($core, $vfsGeneratedPathRoot);


        $result = $core->generate($tableName);
        $this->assertTrue($result);

        // Check for generated class file
        $expectedClassPath = $vfsGeneratedPathRoot . '/Generated/' . $tableName . '.php';
        $this->assertFileExists($expectedClassPath);
        $classContent = file_get_contents($expectedClassPath);
        $this->assertStringContainsString("namespace {$namespace}\\Generated;", $classContent);
        $this->assertStringContainsString("abstract class {$tableName} extends \\GCWorld\\ORM\\Abstracts\\DirectSingle", $classContent);
        $this->assertStringContainsString("const CLASS_TABLE = '{$tableName}';", $classContent);
        $this->assertStringContainsString("const CLASS_PRIMARY = 'id';", $classContent);
        $this->assertStringContainsString("public \$id;", $classContent); // var_visibility = public
        $this->assertStringContainsString("public \$name;", $classContent);
        $this->assertStringContainsString("public function getName(", $classContent); // get_set_funcs = true
        $this->assertStringContainsString("public function setName(", $classContent);
        $this->assertStringContainsString("function jsonSerialize()", $classContent); // json_serialize = true

        // Check for generated trait file
        $expectedTraitPath = $vfsGeneratedPathRoot . '/Generated/Traits/' . $tableName . '.php';
        $this->assertFileExists($expectedTraitPath);
        $traitContent = file_get_contents($expectedTraitPath);
        $this->assertStringContainsString("namespace {$namespace}\\Generated\\Traits;", $traitContent);
        $this->assertStringContainsString("trait {$tableName}", $traitContent);
        $this->assertStringContainsString("public \$name;", $traitContent); // Only non-PK fields, visibility from config
        $this->assertStringNotContainsString("public \$id;", $traitContent); // PK not in trait normally
        $this->assertStringContainsString("public function getName(", $traitContent);
    }
    
    // More tests to follow for generate() with different configs and schemas,
    // getKeys(), and load().

    public function testGenerateReturnsFalseForCompositePrimaryKey()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'composite_pk_table';
        $this->setupUserConfig([
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U'],
            'tables'  => [$tableName => []]
        ]);

        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id1', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '', 'Comment' => 'PK1'],
            ['Field' => 'id2', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '', 'Comment' => 'PK2'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        $this->assertFalse($core->generate($tableName), "generate() should return false for composite primary keys.");
    }

    public function testGenerateHandlesVariousFieldTypesAndConfigOptions()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'complex_table';
        $userConfig = [
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U', 'audit_handler' => 'DefaultAudit'],
            'options' => [
                'var_visibility' => 'protected', // Test protected
                'get_set_funcs'  => true,
                'json_serialize' => true,
                'use_defaults'   => true,
                'type_hinting'   => true,
                'defaults_override_null' => true,
            ],
            'tables'  => [
                $tableName => [
                    'audit_ignore' => true, // Test this
                    'fields' => [
                        'description' => ['type_hint' => '?string', 'visibility' => 'public'], // Override visibility
                        'is_active'   => ['type_hint' => 'bool'],
                        'created_at'  => ['type_hint' => '\\DateTimeImmutable'],
                        'amount'      => ['type_hint' => 'float'],
                        'settings'    => ['type_hint' => 'array'],
                        'status_enum' => ['type_hint' => '\\My\\StatusEnum', 'backed_enum' => true],
                        'user_uuid'   => ['type_hint' => '?string', 'uuid_field' => true], // Manually flag as UUID
                    ]
                ]
            ]
        ];
        $this->setupUserConfig($userConfig);

        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
            ['Field' => 'description', 'Type' => 'text', 'Null' => 'YES', 'Key' => '', 'Default' => 'default desc', 'Extra' => '', 'Comment' => ''],
            ['Field' => 'is_active', 'Type' => 'tinyint(1)', 'Null' => 'NO', 'Key' => '', 'Default' => '1', 'Extra' => '', 'Comment' => ''],
            ['Field' => 'created_at', 'Type' => 'datetime', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ['Field' => 'amount', 'Type' => 'decimal(10,2)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ['Field' => 'settings', 'Type' => 'json', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ['Field' => 'status_enum', 'Type' => 'varchar(50)', 'Null' => 'NO', 'Key' => '', 'Default' => 'pending', 'Extra' => '', 'Comment' => ''],
            ['Field' => 'user_uuid', 'Type' => 'binary(16)', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => 'User UUID'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());

        $stmtKeys = $this->prophesize(PDOStatement::class); // For getKeys()
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1]])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        $vfsGeneratedPathRoot = $this->vfsPath . '/orm_generated_output_complex';
        mkdir($vfsGeneratedPathRoot);
        $masterLocationProp->setValue($core, $vfsGeneratedPathRoot);

        $this->assertTrue($core->generate($tableName));
        $classPath = $vfsGeneratedPathRoot . '/Generated/' . $tableName . '.php';
        $this->assertFileExists($classPath);
        $classContent = file_get_contents($classPath);

        // Check audit_ignore effect
        $this->assertStringContainsString('protected bool $_audit = false;', $classContent);
        // Check var_visibility from options and field override
        $this->assertStringContainsString('protected int $id;', $classContent); // Default protected
        $this->assertStringContainsString('public ?string $description;', $classContent); // Overridden to public
        // Check type hints from config and defaults
        $this->assertStringContainsString('public function getDescription(): ?string', $classContent);
        $this->assertStringContainsString('public function getIsActive(): bool', $classContent);
        $this->assertStringContainsString('public function getCreatedAt(): \DateTimeImmutable', $classContent);
        $this->assertStringContainsString('public function getAmount(): ?float', $classContent);
        $this->assertStringContainsString('public function getSettings(): ?array', $classContent);
        $this->assertStringContainsString('public function getStatusEnum(): \My\StatusEnum', $classContent);
        $this->assertStringContainsString('public function getUserUuid(): ?string', $classContent); // From field config
        $this->assertStringContainsString('public function getUserUuidAsString(): ?string', $classContent); // For UUID field
        
        // Check backed enum handling in setter
        $this->assertStringContainsString('public function setStatusEnum(\My\StatusEnum $value): static', $classContent);
        $this->assertStringContainsString('$this->set(\'status_enum\', $value->value);', $classContent);
        
        // Check UUID handling in setter
        $this->assertStringContainsString('public function setUserUuid(?string $value): static', $classContent);
        $this->assertStringContainsString("Uuid::fromString(\$value)->getBytes()", $classContent);
    }

    public function testGetKeys()
    {
        $tableName = 'table_with_indexes';
        $this->setupUserConfig(['general'=>['common'=>'C','user'=>'U']]); // Minimal
        $core = new Core('Test\\Namespace', $this->commonMock->reveal());

        $stmtKeys = $this->prophesize(PDOStatement::class);
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1],
            ['Key_name' => 'uq_email', 'Non_unique' => 0, 'Column_name' => 'email', 'Seq_in_index' => 1],
            ['Key_name' => 'uq_multi', 'Non_unique' => 0, 'Column_name' => 'col_a', 'Seq_in_index' => 1],
            ['Key_name' => 'uq_multi', 'Non_unique' => 0, 'Column_name' => 'col_b', 'Seq_in_index' => 2],
            ['Key_name' => 'idx_name', 'Non_unique' => 1, 'Column_name' => 'name', 'Seq_in_index' => 1],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $keys = $core->getKeys($tableName);

        $this->assertEquals('id', $keys['primary']);
        $this->assertArrayHasKey('uq_email', $keys['uniques']);
        $this->assertCount(1, $keys['uniques']['uq_email']);
        $this->assertEquals('email', $keys['uniques']['uq_email'][0]['Column_name']);
        $this->assertArrayHasKey('uq_multi', $keys['uniques']);
        $this->assertCount(2, $keys['uniques']['uq_multi']); // Both parts of multi-column unique key
        $this->assertEquals('col_a', $keys['uniques']['uq_multi'][0]['Column_name']);
        $this->assertEquals('col_b', $keys['uniques']['uq_multi'][1]['Column_name']);
        $this->assertArrayNotHasKey('idx_name', $keys['uniques'], "Non-unique keys should not be in uniques array.");
    }

    public function testLoadGeneratedClass()
    {
        $namespace = 'GCWorld\\ORM\\Tests\\Core\\VFSGenerated'; // Use a namespace test runner can autoload from VFS
        $tableName = 'loadable_table';
        $className = $tableName; // Class name is same as table name
        $fullClassName = $namespace . '\\Generated\\' . $className;

        // Minimal config for generation
        $this->setupUserConfig([
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U'],
            'options' => ['get_set_funcs' => false], // Simpler class for loading
            'tables'  => [$tableName => ['constructor' => 'public']]
        ]);

        // Mock DB for generation
        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => 'PK'],
            ['Field' => 'data', 'Type' => 'varchar(50)', 'Null' => 'YES', 'Key' => '', 'Default' => 'test', 'Extra' => '', 'Comment' => ''],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());
        $stmtKeys = $this->prophesize(PDOStatement::class); // For getKeys()
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1]])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());


        $core = new Core($namespace, $this->commonMock->reveal());
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        // Output generated files directly into VFS root for easier include path.
        // The Core class prepends /Generated and /Generated/Traits itself.
        // So master_location should be the root of where "Generated" folder will be created.
        $vfsGeneratedBase = $this->vfsPath . '/load_test_output';
        mkdir($vfsGeneratedBase);
        $masterLocationProp->setValue($core, $vfsGeneratedBase);
        
        $this->assertTrue($core->generate($tableName));
        $generatedClassFile = $vfsGeneratedBase . '/Generated/' . $className . '.php';
        $this->assertFileExists($generatedClassFile);

        // Make VFS path includable
        // This is tricky. PHPUnit runs in its own context.
        // A simple way is to use vfsStream::inspect()->includeFile().
        vfsStream::inspect(vfsStream::url('root/load_test_output/Generated/'.$className.'.php'))->includeFile();
        // Check if class now exists
        $this->assertTrue(class_exists($fullClassName, false), "Class $fullClassName should exist after include.");

        // Test load()
        // The load method expects $args[0] to be class name, and then common, then PK.
        // It internally shifts $args[0] to be $common, and $args[1] (if present) as PK.
        // The actual generated class constructor is `__construct($primary_id = null, $defaults = null)`
        // and parent `DirectSingle` constructor is `__construct($primary_id = null, array $defaults = null, CommonInterface $common = null)`
        // `Core::load` calls `newInstanceArgs` with `[$this->master_common, $primary_id_from_args, ...]`
        
        // For `load('ClassName', $pk)`, it becomes `new ClassName($pk, null, $this->master_common)`
        // (if DirectSingle constructor is used for mapping)
        // The generated class's constructor is `parent::__construct($primary_id, $defaults)`
        // The DirectSingle constructor is `__construct($primary_id = null, array $defaults = null, CommonInterface $common = null)`
        // When Core->load calls newInstanceArgs($args), $args[0] is common.
        // $args becomes effectively: [$this->master_common, $primary_id_arg, $defaults_arg]

        // Mock the DirectSingle's _load method which would be called by constructor if PK is passed
        // To do this, we need to know that the generated class extends DirectSingle.
        // For this test, we assume it does.
        // This is getting too complex for a simple load test due to mocking the parent's load.
        // Simpler: just check if an instance is created.

        $loadedInstance = $core->load($className, 123); // PK = 123
        $this->assertInstanceOf($fullClassName, $loadedInstance);
    }

    public function testGenerateCreatesFactoryMethods()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'table_for_factories';
        $this->setupUserConfig([
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U'],
            'options' => ['get_set_funcs' => true, 'type_hinting' => true],
            'tables'  => [$tableName => []]
        ]);

        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => 'PK'],
            ['Field' => 'email', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => 'Email Address'],
            ['Field' => 'username', 'Type' => 'varchar(100)', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => 'Username'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());

        $stmtKeys = $this->prophesize(PDOStatement::class);
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1],
            ['Key_name' => 'uq_email', 'Non_unique' => 0, 'Column_name' => 'email', 'Seq_in_index' => 1],
            ['Key_name' => 'uq_username', 'Non_unique' => 0, 'Column_name' => 'username', 'Seq_in_index' => 1],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        $vfsGeneratedPathRoot = $this->vfsPath . '/orm_generated_factories';
        mkdir($vfsGeneratedPathRoot);
        $masterLocationProp->setValue($core, $vfsGeneratedPathRoot);

        $this->assertTrue($core->generate($tableName));
        $classPath = $vfsGeneratedPathRoot . '/Generated/' . $tableName . '.php';
        $classContent = file_get_contents($classPath);

        // Check for factory methods based on unique keys (uq_email, uq_username)
        $this->assertStringContainsString('public static function factoryUqEmailAll(string $email): static', $classContent);
        $this->assertStringContainsString('public static function factoryUqEmail(int $id): static', $classContent);
        $this->assertStringContainsString('public static function findUqEmail(string $email)', $classContent);

        $this->assertStringContainsString('public static function factoryUqUsernameAll(string $username): static', $classContent);
        $this->assertStringContainsString('public static function factoryUqUsername(int $id): static', $classContent);
        $this->assertStringContainsString('public static function findUqUsername(string $username)', $classContent);
    }

    public function testGenerateCreatesSaveTestMethod()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'table_for_savetest';
        $this->setupUserConfig([
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U'],
            'tables'  => [
                $tableName => [
                    'fields' => [
                        'name' => ['required' => true],
                        'user_uuid' => ['uuid_field' => true, 'required' => true], // A required UUID field
                    ]
                ]
            ]
        ]);

        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => 'PK'],
            ['Field' => 'name', 'Type' => 'varchar(255)', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => 'Name'],
            ['Field' => 'user_uuid', 'Type' => 'binary(16)', 'Null' => 'NO', 'Key' => '', 'Default' => null, 'Extra' => '', 'Comment' => 'User UUID'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());

        // Minimal keys for this test
        $stmtKeys = $this->prophesize(PDOStatement::class);
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1]
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        $vfsGeneratedPathRoot = $this->vfsPath . '/orm_generated_savetest';
        mkdir($vfsGeneratedPathRoot);
        $masterLocationProp->setValue($core, $vfsGeneratedPathRoot);

        $this->assertTrue($core->generate($tableName));
        $classPath = $vfsGeneratedPathRoot . '/Generated/' . $tableName . '.php';
        $classContent = file_get_contents($classPath);

        $this->assertStringContainsString('public function saveTest(): void', $classContent);
        $this->assertStringContainsString("if(empty(\$this->name)) {\n    \$cExceptions->addException(new ModelRequiredFieldException('name'));", $classContent);
        $this->assertStringContainsString("if(empty(\$this->user_uuid)) {\n    \$cExceptions->addException(new ModelRequiredFieldException('user_uuid'));", $classContent);
        $this->assertStringContainsString("Uuid::fromBytes(\$this->user_uuid);", $classContent); // Check for UUID validation
        $this->assertStringContainsString("catch (Exception \$e) {\n    \$cExceptions->addException(new ModelInvalidUUIDFormatException('user_uuid'));", $classContent);
    }
    
    public function testGenerateWithSaveHook()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'table_with_savehook';
        $saveHookCall = '\My\Custom\Hooks::mySaveHook';
        $this->setupUserConfig([
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U'],
            'tables'  => [$tableName => ['save_hook' => $saveHookCall]]
        ]);

        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => 'PK'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());
        $stmtKeys = $this->prophesize(PDOStatement::class);
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1]])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        $vfsGeneratedPathRoot = $this->vfsPath . '/orm_generated_savehook';
        mkdir($vfsGeneratedPathRoot);
        $masterLocationProp->setValue($core, $vfsGeneratedPathRoot);

        $this->assertTrue($core->generate($tableName));
        $classPath = $vfsGeneratedPathRoot . '/Generated/' . $tableName . '.php';
        $classContent = file_get_contents($classPath);

        $this->assertStringContainsString('protected function saveHook(array $before, array $after, array $changed): void', $classContent);
        $this->assertStringContainsString($saveHookCall.'($table_name, $primary_id, $before, $after, $changed);', $classContent);
    }

    public function testGenerateHandlesDescriptions()
    {
        $namespace = 'Test\\App\\Generated';
        $tableName = 'table_with_descriptions';
        $descDirVfs = $this->vfsPath . '/descriptions_dir';
        mkdir($descDirVfs);

        $this->setupUserConfig([
            'version' => Config::VERSION,
            'general' => ['common' => 'C', 'user' => 'U'],
            'descriptions' => [
                'enabled'    => true,
                'desc_dir'   => '../descriptions_dir', // Relative to user config file's parent
                'desc_trait' => 'GCWorld\\ORM\\Traits\\ORMFieldsTrait', // Using default
            ],
            'tables' => [$tableName => []]
        ]);
        
        // User config file will be at $this->vfsPath . '/test_user_orm_config.yml'
        // So, desc_dir will be $this->vfsPath (parent of test_user_orm_config.yml) then ../descriptions_dir
        // This means $this->vfsPath . '/descriptions_dir'

        $stmtFields = $this->prophesize(PDOStatement::class);
        $stmtFields->execute()->shouldBeCalled();
        $stmtFields->fetchAll(PDO::FETCH_ASSOC)->willReturn([
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => 'PK Comment'],
            ['Field' => 'name', 'Type' => 'varchar(100)', 'Null' => 'YES', 'Key' => '', 'Default' => 'Anon', 'Extra' => '', 'Comment' => 'Name Comment'],
        ])->shouldBeCalled();
        $this->dbMock->prepare('SHOW FULL COLUMNS FROM '.$tableName)->willReturn($stmtFields->reveal());
        $stmtKeys = $this->prophesize(PDOStatement::class);
        $stmtKeys->execute()->shouldBeCalled();
        $stmtKeys->fetchAll(PDO::FETCH_ASSOC)->willReturn([['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Column_name' => 'id', 'Seq_in_index' => 1]])->shouldBeCalled();
        $this->dbMock->prepare('SHOW INDEX FROM '.$tableName)->willReturn($stmtKeys->reveal());

        $core = new Core($namespace, $this->commonMock->reveal());
        $reflectionCore = new \ReflectionClass(Core::class);
        $masterLocationProp = $reflectionCore->getProperty('master_location');
        $masterLocationProp->setAccessible(true);
        $vfsGeneratedPathRoot = $this->vfsPath . '/orm_generated_descriptions';
        mkdir($vfsGeneratedPathRoot);
        $masterLocationProp->setValue($core, $vfsGeneratedPathRoot);

        $this->assertTrue($core->generate($tableName));
        $classPath = $vfsGeneratedPathRoot . '/Generated/' . $tableName . '.php';
        $classContent = file_get_contents($classPath);

        $this->assertStringContainsString('use GCWorld\\Interfaces\\ORMDescriptionInterface;', $classContent);
        $this->assertStringContainsString('use GCWorld\\ORM\\Traits\\ORMFieldsTrait;', $classContent);
        $this->assertStringContainsString('implements \\GCWorld\\Interfaces\\ORMDescriptionInterface', $classContent);
        $this->assertStringContainsString('use \\GCWorld\\ORM\\Traits\\ORMFieldsTrait;', $classContent);
        $this->assertStringContainsString('public static array $ORM_FIELDS = [', $classContent);
        $this->assertStringContainsString("'id' => [", $classContent);
        $this->assertStringContainsString("'title' => 'id'", $classContent);
        $this->assertStringContainsString("'tech' => 'int(11) - PK Comment'", $classContent);
        $this->assertStringContainsString("'name' => [", $classContent);
        $this->assertStringContainsString("'maxlen' => 100", $classContent); // From varchar(100)

        // Check that description file was created/updated in VFS
        $expectedDescFilePath = $descDirVfs . '/' . $tableName . '.yml';
        $this->assertFileExists($expectedDescFilePath);
        $descFileContent = Yaml::parseFile($expectedDescFilePath);
        $this->assertArrayHasKey('id', $descFileContent);
        $this->assertEquals('id', $descFileContent['id']['title']);
    }
}

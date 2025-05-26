<?php

namespace GCWorld\ORM\Tests;

use GCWorld\ORM\DirectDBClass;
use GCWorld\ORM\Abstracts\DirectSingle; // To check inheritance
use GCWorld\ORM\CommonLoader;
use GCWorld\ORM\Config; // For setting up underlying config for CommonLoader
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use Redis; // For mocking Redis
use PDO;
use PDOStatement;
use Symfony\Component\Yaml\Yaml; // For creating pointer config

// Concrete subclass for testing DirectDBClass
class ConcreteDirectDBClass extends DirectDBClass
{
    const CLASS_TABLE = 'test_db_table';
    const CLASS_PRIMARY = 'id';

    public static array $dbInfo = [
        'id'        => ['type' => 'int', 'options' => ['unsigned' => true, 'auto_increment' => true]],
        'name'      => ['type' => 'varchar', 'length' => 255],
        'email'     => ['type' => 'varchar', 'length' => 255, 'default' => null],
        'is_active' => ['type' => 'tinyint', 'length' => 1, 'default' => 0],
    ];

    // Properties corresponding to $dbInfo keys will be dynamically handled by DirectSingle
    // We can declare them here for type hinting and clarity if desired, but it's not strictly necessary
    // for DirectSingle's magic property handling (if it has any beyond direct access)
    // However, DirectSingle directly sets properties: public int $id; public string $name; etc.
    public int $id;
    public string $name;
    public ?string $email = null;
    public int $is_active = 0;


    // Expose parent's (DirectSingle) _hasChanged for easier testing of set methods
    public function hasChangedPublic(): bool
    {
        return $this->_hasChanged();
    }
    public function getChangedPublic(): array
    {
        return $this->_getChanged();
    }
}

class DirectDBClassTest extends TestCase
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

    protected function setUp(): void
    {
        $this->root = vfsStream::setup('root');
        $this->vfsPath = vfsStream::url('root');

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
        $this->setupPointerConfigMinimal();

        $this->commonMock = $this->prophesize(CommonInterface::class);
        $this->dbMock = $this->prophesize(DatabaseInterface::class);
        $this->redisMock = $this->prophesize(Redis::class);
        $this->pdoStatementMock = $this->prophesize(PDOStatement::class);

        $this->commonMock->getDatabase(Argument::any())->willReturn($this->dbMock->reveal());
        $this->commonMock->getCache(Argument::any())->willReturn($this->redisMock->reveal());
        $this->commonMock->getConfig('audit')->willReturn(['prefix' => '_Audit_', 'enable' => true]);

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
        
        $reflection = new \ReflectionClass(CommonLoader::class);
        $commonProp = $reflection->getProperty('common');
        $commonProp->setAccessible(true);
        $commonProp->setValue(null, null);
    }

    private function setupPointerConfigMinimal(): void
    {
        $userConfigVfsPath = $this->vfsPath . '/minimal_user_config_for_ddbc.yml';
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

    public function testInheritance()
    {
        $obj = new ConcreteDirectDBClass();
        $this->assertInstanceOf(DirectSingle::class, $obj);
    }

    public function testConstructorInitializesViaDirectSingle()
    {
        // Test that DirectSingle's constructor logic is hit (e.g., DB load on PK)
        $pk = 1;
        $dbData = ['id' => $pk, 'name' => 'DB Name', 'email' => 'db@example.com', 'is_active' => 1];
        
        $this->redisMock->hGet('GCWorld\ORM\Tests\ConcreteDirectDBClass', 'key_'.$pk)->willReturn(false); // Cache miss
        $this->dbMock->prepare("SELECT  * FROM test_db_table WHERE id = :id")
            ->willReturn($this->pdoStatementMock->reveal());
        $this->pdoStatementMock->execute([':id' => $pk])->shouldBeCalled();
        $this->pdoStatementMock->fetch(PDO::FETCH_ASSOC)->willReturn($dbData)->shouldBeCalled();
        $this->pdoStatementMock->closeCursor()->shouldBeCalled();
        $this->redisMock->hSet('GCWorld\ORM\Tests\ConcreteDirectDBClass', 'key_'.$pk, Argument::type('string'))->shouldBeCalled();

        $obj = new ConcreteDirectDBClass($pk);
        $this->assertEquals($pk, $obj->id);
        $this->assertEquals('DB Name', $obj->name);
    }

    public function testGetMethod()
    {
        $obj = new ConcreteDirectDBClass();
        // Properties are public in ConcreteDirectDBClass for direct manipulation in this test context
        $obj->name = "Test Name";
        $obj->id = 123;

        $this->assertEquals("Test Name", $obj->get('name'));
        $this->assertEquals(123, $obj->get('id'));
        $this->assertNull($obj->get('non_existent_key')); // DirectSingle's get returns $this->{$key}
    }

    public function testSetMethod()
    {
        $obj = new ConcreteDirectDBClass();
        
        $obj->set('name', 'New Name');
        $this->assertEquals('New Name', $obj->name); // Assuming direct property access or public getter
        $this->assertTrue($obj->hasChangedPublic());
        $this->assertContains('name', $obj->getChangedPublic());

        $obj->set('email', 'new@example.com');
        $this->assertEquals('new@example.com', $obj->email);
        $this->assertContains('email', $obj->getChangedPublic());

        // Test setting to same value (should not add to _changed if not already there,
        // or remain if already changed from original)
        $obj->set('is_active', 0); // Default is 0, so this shouldn't mark as changed initially.
                                   // But if object was loaded, it might be different.
                                   // For a new object, this is setting to its default.
        $this->assertNotContains('is_active', $obj->getChangedPublic(), "Setting to default on new object shouldn't mark changed");

        $obj->set('is_active', 1);
        $this->assertContains('is_active', $obj->getChangedPublic());
        $obj->set('is_active', 1); // Set to same (changed) value
        $this->assertContains('is_active', $obj->getChangedPublic());
    }

    public function testGetArrayMethod()
    {
        $obj = new ConcreteDirectDBClass();
        $obj->id = 1;
        $obj->name = "Array Test";
        $obj->email = "array@test.com";
        $obj->is_active = 1;

        $subset = $obj->getArray(['name', 'email']);
        $this->assertCount(2, $subset);
        $this->assertEquals("Array Test", $subset['name']);
        $this->assertEquals("array@test.com", $subset['email']);

        $all = $obj->getArray(['id', 'name', 'email', 'is_active']);
        $this->assertEquals(['id' => 1, 'name' => "Array Test", 'email' => "array@test.com", 'is_active' => 1], $all);
    }

    public function testSetArrayMethod()
    {
        $obj = new ConcreteDirectDBClass();
        $data = [
            'name' => 'Set By Array',
            'email' => 'setarray@example.com',
            'is_active' => 1
        ];
        $obj->setArray($data);

        $this->assertEquals('Set By Array', $obj->name);
        $this->assertEquals('setarray@example.com', $obj->email);
        $this->assertEquals(1, $obj->is_active);
        $this->assertTrue($obj->hasChangedPublic());
        $this->assertContains('name', $obj->getChangedPublic());
        $this->assertContains('email', $obj->getChangedPublic());
        $this->assertContains('is_active', $obj->getChangedPublic());
    }
}

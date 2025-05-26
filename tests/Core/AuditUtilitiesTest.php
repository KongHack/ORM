<?php

namespace GCWorld\ORM\Tests\Core;

use GCWorld\ORM\Core\AuditUtilities;
use GCWorld\ORM\Helpers\CleanAuditData;
use GCWorld\ORM\Config; // For setting up config for tests
use PHPUnit\Framework\TestCase;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Yaml\Yaml;

// Mock BackedEnum for testing if PHP version < 8.1 or for isolation
if (PHP_VERSION_ID < 80100 && !interface_exists(\BackedEnum::class, false)) {
    interface BackedEnum {
        public function from(string|int $value): static;
        public static function tryFrom(string|int $value): ?static;
        public readonly string|int $value;
        public readonly string $name; // Though not strictly part of BackedEnum, often present
    }
    enum MockStringBackedEnum: string implements BackedEnum {
        case TestValue = 'test_string_value';
        public function from(string|int $value): static { return static::tryFrom($value); }
        public static function tryFrom(string|int $value): ?static { return self::TestValue->value === $value ? self::TestValue : null; }
    }
    enum MockIntBackedEnum: int implements BackedEnum {
        case TestValue = 123;
        public function from(string|int $value): static { return static::tryFrom($value); }
        public static function tryFrom(string|int $value): ?static { return self::TestValue->value === $value ? self::TestValue : null; }
    }
} elseif (PHP_VERSION_ID >= 80100) {
    // Define enums directly if PHP >= 8.1
    enum MockStringBackedEnumReal: string {
        case TestValue = 'test_string_value';
    }
    enum MockIntBackedEnumReal: int {
        case TestValue = 123;
    }
}


class AuditUtilitiesTest extends TestCase
{
    private vfsStreamDirectory $root;
    private string $vfsPath;

    // Path to the real config directory for the package
    private string $realPackageConfigDir;
    private string $realPackagePointerConfigYmlPath;

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

    private function createRealPackagePointerConfig(string $vfsUserConfigPath): void
    {
        $pointerConfig = ['config_path' => $vfsUserConfigPath];
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

    private function setupConfigForTable(string $tableName, array $tableConfigData): void
    {
        $userConfigVfsPath = $this->vfsPath . '/audit_test_user_config.yml';
        $userConfigData = [
            'version' => Config::VERSION,
            'general' => ['common' => 'Test', 'user' => 'Test'], // Minimal general section
            'tables'  => [
                $tableName => $tableConfigData,
            ],
        ];
        file_put_contents($userConfigVfsPath, Yaml::dump($userConfigData));
        $relativePathToVfsUserConfig = $this->calculateRelativePath(
            dirname((new \ReflectionClass(Config::class))->getFileName()),
            $userConfigVfsPath
        );
        $this->createRealPackagePointerConfig($relativePathToVfsUserConfig);
    }


    public function testCleanDataNoChanges()
    {
        $this->setupConfigForTable('test_table', []); // No specific field configs
        $before = ['name' => 'John', 'age' => 30];
        $after = ['name' => 'John', 'age' => 30];
        $result = AuditUtilities::cleanData('test_table', $after, $before);

        $this->assertInstanceOf(CleanAuditData::class, $result);
        $this->assertEmpty($result->getBefore());
        $this->assertEmpty($result->getAfter());
    }

    public function testCleanDataSimpleValueChanges()
    {
        $this->setupConfigForTable('test_table', []);
        $before = ['name' => 'John', 'age' => 30, 'active' => true, 'city' => 'NY'];
        $after = ['name' => 'Jane', 'age' => 31, 'active' => false, 'city' => 'NY']; // city unchanged
        $result = AuditUtilities::cleanData('test_table', $after, $before);

        $expectedBefore = ['name' => 'John', 'age' => 30, 'active' => true];
        $expectedAfter = ['name' => 'Jane', 'age' => 31, 'active' => false];

        $this->assertEquals($expectedBefore, $result->getBefore());
        $this->assertEquals($expectedAfter, $result->getAfter());
    }

    public function testCleanDataBackedEnumHandling()
    {
        $this->setupConfigForTable('test_table', []);
        // Use real enums if PHP >= 8.1, otherwise the polyfilled mocks
        $enumStringBefore = (PHP_VERSION_ID >= 80100) ? MockStringBackedEnumReal::TestValue : MockStringBackedEnum::TestValue;
        $enumIntAfter = (PHP_VERSION_ID >= 80100) ? MockIntBackedEnumReal::TestValue : MockIntBackedEnum::TestValue;

        $before = ['status' => $enumStringBefore, 'code' => 100];
        $after = ['status' => 'active', 'code' => $enumIntAfter];
        $result = AuditUtilities::cleanData('test_table', $after, $before);
        
        $expectedBefore = ['status' => 'test_string_value', 'code' => 100];
        $expectedAfter = ['status' => 'active', 'code' => 123];

        $this->assertEquals($expectedBefore, $result->getBefore());
        $this->assertEquals($expectedAfter, $result->getAfter());
    }
    
    public function testCleanDataEmptyValueHandling()
    {
        $this->setupConfigForTable('test_table', []);
        $before = ['name' => 'John', 'description' => 'text', 'notes' => null, 'count' => 0];
        $after  = ['name' => 'John', 'description' => '',   'notes' => 'new', 'count' => 5];
        $result = AuditUtilities::cleanData('test_table', $after, $before);

        // empty('') is true, empty(null) is true.
        // empty(0) is true.
        // The logic is: if ($after[$k] !== $v), then if(empty($v)) $B[$k] = '', if(empty($after[$k])) $A[$k] = ''
        $expectedBefore = ['description' => 'text', 'notes' => '', 'count' => '']; // null and 0 become '' if changed
        $expectedAfter  = ['description' => '',   'notes' => 'new','count' => 5]; // '' remains ''

        $this->assertEquals($expectedBefore, $result->getBefore());
        $this->assertEquals($expectedAfter, $result->getAfter());
    }

    public function testCleanDataUuidHandling()
    {
        $this->setupConfigForTable('test_table', []);
        $uuidBytes = Uuid::uuid4()->getBytes();
        $uuidString = Uuid::fromBytes($uuidBytes)->toString();

        $before = ['item_uuid' => $uuidBytes, 'other_field' => 'data'];
        $after  = ['item_uuid' => $uuidBytes, 'other_field' => 'changed']; // uuid same, other changed
        $result = AuditUtilities::cleanData('test_table', $after, $before);
        // The UUID should not appear in diff if it's the same, even if it would be transformed.
        // Let's test a changed UUID
        $newUuidBytes = Uuid::uuid4()->getBytes();
        $newUuidString = Uuid::fromBytes($newUuidBytes)->toString();

        $before2 = ['item_uuid' => $uuidBytes];
        $after2  = ['item_uuid' => $newUuidBytes];
        $result2 = AuditUtilities::cleanData('test_table', $after2, $before2);

        $this->assertEquals(['item_uuid' => $uuidString], $result2->getBefore());
        $this->assertEquals(['item_uuid' => $newUuidString], $result2->getAfter());
    }

    public function testCleanDataBinaryHandling()
    {
        $this->setupConfigForTable('test_table', []);
        $binaryData = random_bytes(16); // Raw binary string
        $before = ['data_field' => $binaryData, 'text_field' => "normal text"];
        $after  = ['data_field' => $binaryData, 'text_field' => "changed text"]; // data_field same
        $result = AuditUtilities::cleanData('test_table', $after, $before);
        
        $this->assertEquals(['text_field' => 'normal text'], $result->getBefore());
        $this->assertEquals(['text_field' => 'changed text'], $result->getAfter());

        // Test changed binary data
        $newBinaryData = random_bytes(16);
        $before2 = ['data_field' => $binaryData];
        $after2  = ['data_field' => $newBinaryData];
        $result2 = AuditUtilities::cleanData('test_table', $after2, $before2);

        $this->assertEquals(['data_field' => base64_encode($binaryData)], $result2->getBefore());
        $this->assertEquals(['data_field' => base64_encode($newBinaryData)], $result2->getAfter());
    }

    public function testCleanDataTableLevelAuditIgnore()
    {
        $this->setupConfigForTable('ignored_table', ['audit_ignore' => true]);
        $before = ['name' => 'John'];
        $after  = ['name' => 'Jane'];
        $result = AuditUtilities::cleanData('ignored_table', $after, $before);

        $this->assertEmpty($result->getBefore());
        $this->assertEmpty($result->getAfter());
    }

    public function testCleanDataFieldLevelAuditIgnore()
    {
        $tableConfig = [
            'fields' => [
                'name' => ['audit_ignore' => false], // Explicitly not ignored
                'secret_field' => ['audit_ignore' => true],
                'age' => [], // Not ignored by default
            ]
        ];
        $this->setupConfigForTable('mixed_table', $tableConfig);
        $before = ['name' => 'John', 'secret_field' => 'secret1', 'age' => 30];
        $after  = ['name' => 'Jane', 'secret_field' => 'secret2', 'age' => 31];
        $result = AuditUtilities::cleanData('mixed_table', $after, $before);

        // secret_field changes should be ignored
        $expectedBefore = ['name' => 'John', 'age' => 30];
        $expectedAfter  = ['name' => 'Jane', 'age' => 31];

        $this->assertEquals($expectedBefore, $result->getBefore());
        $this->assertEquals($expectedAfter, $result->getAfter());
    }
    
    public function testCleanDataKeyAddedInAfterNotIncluded()
    {
        $this->setupConfigForTable('test_table', []);
        $before = ['name' => 'John'];
        $after  = ['name' => 'John', 'age' => 30]; // age added
        $result = AuditUtilities::cleanData('test_table', $after, $before);

        $this->assertEmpty($result->getBefore(), "Should be empty as 'name' didn't change, and 'age' was not in before");
        $this->assertEmpty($result->getAfter(), "Should be empty as 'name' didn't change, and 'age' was not in before");
    }

    public function testCleanDataKeyRemovedFromBeforeNotIncluded()
    {
        $this->setupConfigForTable('test_table', []);
        $before = ['name' => 'John', 'age' => 30];
        $after  = ['name' => 'John']; // age removed
        $result = AuditUtilities::cleanData('test_table', $after, $before);
        // The loop iterates over $before. If $after[$k] is not set for $k='age', it's not processed.
        $this->assertEmpty($result->getBefore());
        $this->assertEmpty($result->getAfter());
    }


    // Tests for isBinary helper method
    public function testIsBinaryNonScalar()
    {
        $this->assertTrue(AuditUtilities::isBinary([]), "Array should be considered binary (or non-string)");
        $this->assertTrue(AuditUtilities::isBinary(new \stdClass()), "Object should be considered binary");
    }

    public function testIsBinaryWithDetectableEncoding()
    {
        $this->assertFalse(AuditUtilities::isBinary('Hello World'), "ASCII string is not binary");
        $this->assertFalse(AuditUtilities::isBinary('Héllo Wörld'), "UTF-8 string is not binary"); // common UTF-8
    }
    
    public function testIsBinaryWithNullAndSpecialChars()
    {
        $this->assertTrue(AuditUtilities::isBinary("\x00\x01\x02"), "String with NUL bytes is binary");
        $this->assertTrue(AuditUtilities::isBinary(chr(127)), "String with DEL char is binary by preg_match part");
        $this->assertFalse(AuditUtilities::isBinary(""), "Empty string is not binary");
        $this->assertFalse(AuditUtilities::isBinary("newlines\nand\rtabs\t"), "String with only standard whitespace is not binary");
    }
}

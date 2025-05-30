<?php

namespace GCWorld\ORM;

use GCWorld\Interfaces\CommonInterface;
use Monolog\Logger;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;
use Nette\PhpGenerator\TraitType;
use ReflectionClass;
use PDO;
use Exception;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Core
 * @package GCWorld\ORM
 */
class Core
{
    protected readonly Config $cConfig;

    /** @var CommonInterface|\GCWorld\Common\Common */
    protected readonly CommonInterface $master_common;
    protected readonly string $master_namespace;
    protected readonly ?string $master_location;
    protected readonly array $config;
    protected Logger $logger; // Not readonly due to setLogger

    protected readonly string $var_visibility;
    protected readonly bool $get_set_funcs;
    protected readonly bool $json_serialize;
    protected readonly bool $use_defaults;
    protected readonly bool $defaults_override_null;
    protected readonly bool $type_hinting;
    protected readonly bool $audit;

    public function __construct(string $namespace, CommonInterface $common)
    {
        $this->master_namespace = $namespace;
        $this->master_common    = $common;
        $this->master_location  = __DIR__;

        $cConfig       = new Config();
        $this->cConfig = $cConfig;
        $config        = $cConfig->getConfig(); // Local var, not the property
        $this->config  = $config; // Assign to readonly property

        // Initialize other readonly properties based on config
        $this->audit = !(isset($config['general']['audit']) && !$config['general']['audit']);

        $this->get_set_funcs = !(isset($config['options']['get_set_funcs']) && !$config['options']['get_set_funcs']);

        $var_visibility_default = 'public';
        if (isset($config['options']['var_visibility'])
            && in_array($config['options']['var_visibility'], ['public', 'protected'])
        ) {
            $var_visibility_default = $config['options']['var_visibility'];
        }
        $this->var_visibility = $var_visibility_default;

        $this->json_serialize = !(isset($config['options']['json_serialize']) && !$config['options']['json_serialize']);
        $this->use_defaults   = !(isset($config['options']['use_defaults']) && !$config['options']['use_defaults']);
        $this->defaults_override_null = !(isset($config['options']['defaults_override_null']) && !$config['options']['defaults_override_null']);
        $this->type_hinting = (isset($config['options']['type_hinting']) && $config['options']['type_hinting']);

        $this->logger = new Logger('orm_core');
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * @throws Exception
     */
    public function generate(string $table_name): bool
    {
        $this->logger->info('Processing Table: '.$table_name);

        $sql   = 'SHOW FULL COLUMNS FROM '.$table_name;
        $query = $this->master_common->getDatabase()->prepare($sql);
        $query->execute();
        $fields = $query->fetchAll(PDO::FETCH_ASSOC);
        unset($query);
        $config = $this->config['tables'][$table_name] ?? [];

        $this->logger->debug('Found Fields', $fields);
        $this->logger->debug('Found Config', $config);

        $config['constructor']   = $config['constructor'] ?? 'public';
        $config['audit_ignore']  = $config['audit_ignore'] ?? false;
        $config['fields']        = $config['fields'] ?? [];
        $config['cache_ttl']     = $config['cache_ttl'] ?? 0;
        $config['audit_handler'] = ($config['audit_handler'] ?? ($this->config['general']['audit_handler'] ?? null));

        $save_hook      = isset($config['save_hook']);
        $save_hook_call = $config['save_hook'] ?? '';
        if ($save_hook && empty($save_hook_call)) {
            $save_hook = false;
        }

        $cache_after_purge = $config['cache_after_purge'] ?? null;
        if ($cache_after_purge === null) {
            $cache_after_purge = $this->config['options']['cache_after_purge'] ?? false;
        }

        $uuid_fields    = false;
        $auto_increment = false;
        $primaries      = [];
        $max_var_name   = 0;
        $max_var_type   = 0;

        $path = $this->master_location.DIRECTORY_SEPARATOR.'Generated/';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $default = Config::getDefaultFieldConfig();
        foreach ($fields as $i => $row) {
            $this->logger->debug('Processing Field', $row);

            // Do not include virtual fields in the system
            if (stripos($row['Extra'], 'VIRTUAL') !== false) {
                $this->logger->debug('Unsetting Virtual: '.$row['Field']);
                unset($fields[$i]);
                continue;
            }

            if (stristr($row['Key'], 'PRI')) {
                $primaries[] = $row['Field'];
            }
            if (strlen($row['Field']) > $max_var_name) {
                $max_var_name = strlen($row['Field']);
            }
            if (strlen($row['Type']) > $max_var_type) {
                $max_var_type = strlen($row['Type']);
            }
            if (stristr($row['Extra'], 'auto_increment')) {
                $auto_increment = true;
            }
            if (!isset($config['fields'][$row['Field']])) {
                $config['fields'][$row['Field']] = $default;
            } else {
                foreach ($default as $k => $v) {
                    if (!isset($config['fields'][$row['Field']][$k])) {
                        $config['fields'][$row['Field']][$k] = $v;
                    }
                }
            }

            // In the event this isn't set, or if it's false, double check
            if (!isset($config['fields'][$row['Field']]['uuid_field'])
                || !$config['fields'][$row['Field']]['uuid_field']
            ) {
                $config['fields'][$row['Field']]['uuid_field'] = (
                    stripos($row['Field'], '_uuid') !== false
                    && strtolower($row['Type']) == 'binary(16)'
                );
            }

            if (!$uuid_fields
                && isset($config['fields'][$row['Field']]['uuid_field'])
                && $config['fields'][$row['Field']]['uuid_field']
            ) {
                $uuid_fields = true;
            }

            if (isset($config['fields'][$row['Field']]['type_hint'])
                && !empty($config['fields'][$row['Field']]['type_hint'])
                && str_contains($config['fields'][$row['Field']]['type_hint'], '\\')
                && enum_exists($config['fields'][$row['Field']]['type_hint'])
            ) {
                $cReflection = new ReflectionClass($config['fields'][$row['Field']]['type_hint']);
                $config['fields'][$row['Field']]['backed_enum'] = $cReflection->implementsInterface(\BackedEnum::class);
            }
        }

        $this->logger->debug('Discovered Primaries', $primaries);

        if (count($primaries) !== 1) {
            return false;
        }

        $this->logger->debug('Generating Code');

        $filename   = $table_name.'.php';
        $cNamespace = new PhpNamespace('GCWorld\\ORM\\Generated');
        $cClass     = $cNamespace->addClass($table_name);
        $cClass->setAbstract(true);
        $cClass->addConstant('CLASS_TABLE', $table_name)->setPublic();
        $cClass->addComment('Generated Class for Table '.$table_name);
        if ($uuid_fields) {
            $cNamespace->addUse('Ramsey\\Uuid\\Uuid');
        }

        // NOTE: As of nette/php-generator 4.1.2, class aliases are broken.
        // This means we need to add the class extend/implement here instead.
        if ($this->get_set_funcs) {
            $cNamespace->addUse('GCWorld\\ORM\\Abstracts\\DirectSingle');
            $cClass->setExtends('GCWorld\\ORM\\Abstracts\\DirectSingle');
            $cNamespace->addUse('GCWorld\\ORM\\Interfaces\\ProtectedDBInterface');
            $cClass->addImplement('GCWorld\\ORM\\Interfaces\\ProtectedDBInterface');
        } else {
            $cNamespace->addUse('GCWorld\\ORM\\Abstracts\\DirectDBClass');
            $cClass->setExtends('GCWorld\\ORM\\Abstracts\\DirectDBClass');
            $cNamespace->addUse('GCWorld\\ORM\\Interfaces\\PublicDBInterface');
            $cClass->addImplement('GCWorld\\ORM\\Interfaces\\PublicDBInterface');
        }
        $cNamespace->addUse('GCWorld\\ORM\\Interfaces\\GeneratedInterface');
        $cClass->addImplement('GCWorld\\ORM\\Interfaces\\GeneratedInterface');
        $cClass->addConstant('CLASS_PRIMARY', $primaries[0])->setPublic();

        if ($this->json_serialize) {
            $cNamespace->addUse('JsonSerializable');
            $cClass->addImplement('JsonSerializable');
        }
        $cClass->addConstant('AUTO_INCREMENT', $auto_increment)->setPublic();

        $cProperty = $cClass->addProperty('_cacheTTL', (int) $config['cache_ttl']);
        $cProperty->setVisibility('protected');
        $cProperty->addComment('@var int');

        if ($config['cache_ttl'] < 0) {
            $cProperty = $cClass->addProperty('_canCache', false);
            $cProperty->setVisibility('protected');
            $cProperty->addComment('@var bool');
        }

        $cProperty = $cClass->addProperty('_canCacheAfterPurge', $cache_after_purge);
        $cProperty->setVisibility('protected');
        $cProperty->addComment('@var bool');

        $cProperty = $cClass->addProperty('_auditHandler', $config['audit_handler']);
        $cProperty->setVisibility('protected');
        $cProperty->setType('?string');
        $cProperty->addComment('Class Name for audit handler'.PHP_EOL.'@var ?string');
        $cProperty->setNullable(true);

        foreach ($fields as $i => $row) {
            $type = (stristr($row['Type'], 'int') ? 'int   ' : 'string');
            if ($row['Null'] == 'YES') {
                $type .= '|null';
            }

            $default = null;
            if ($this->use_defaults) {
                $default = $this->formatDefault($row);
            }

            $cProperty = $cClass->addProperty($row['Field'], $default);
            $cProperty->setVisibility($this->var_visibility);
            $cProperty->addComment('@var '.$type);
            $cProperty->addComment('@db-info '.$row['Type']);
        }

        $dbInfo = [];
        $enums  = [];
        foreach ($fields as $i => $row) {
            $dbInfo[$row['Field']] = $row['Type'].($row['Comment'] != '' ? ' - '.$row['Comment'] : '');
        }
        $cProperty = $cClass->addProperty('dbInfo', $dbInfo);
        $cProperty->setStatic(true);
        $cProperty->addComment('Contains an array of all fields and the database notation for field type');
        $cProperty->addComment('@var array');

        $cMethodConstructor = $cClass->addMethod('__construct');

        // Let's add some variable defaults to make life easier on us
        if ($config['audit_ignore'] || !$this->audit) {
            $cProperty = $cClass->addProperty('_audit', false);
            $cProperty->setVisibility('protected');
            $cProperty->addComment('Disabled via ORM Config');
            $cProperty->addComment('@var bool');
        }

        // CONSTRUCTOR!
        if ($this->type_hinting) {
            // TODO: Get type of primary and swap out mixed
            $cMethodConstructor->addComment('@param mixed $primary_id');
            $cMethodConstructor->addComment('@param array $defaults');
        } else {
            $cMethodConstructor->addComment('@param mixed $primary_id');
            $cMethodConstructor->addComment('@param mixed $defaults');
        }
        $cMethodConstructor->addParameter('primary_id', null)->setNullable(true);
        $cMethodConstructor->addParameter('defaults', null)->setNullable(true);
        $cMethodConstructor->setVisibility($config['constructor']);
        $cMethodConstructor->setBody('parent::__construct($primary_id, $defaults);');

        if ($this->get_set_funcs) {
            foreach ($fields as $i => $row) {
                $fieldConfig = $config['fields'][$row['Field']];
                if ($fieldConfig['getter_ignore']) {
                    continue;
                }

                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';

                if ($fieldConfig['type_hint'] != '') {
                    $return_type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }

                $cMethod = $cClass->addMethod('get'.$name);
                $cMethod->setPublic();
                $cMethod->addComment('@return '.($row['Null'] == 'YES' ? '?' : '').$return_type);

                if (isset($fieldConfig['backed_enum']) && $fieldConfig['backed_enum']) {
                    $body  = '$val = $this->get(\''.$row['Field'].'\');'.PHP_EOL;
                    $body .= 'return '.$return_type.'::from($val);';

                    $enums[$row['Field']] = $return_type;
                    $cMethod->setBody($body);
                } else {
                    $cMethod->setBody('return $this->get(\''.$row['Field'].'\');');
                }

                if ($fieldConfig['uuid_field']) {
                    $body  = '$value = $this->get(\''.$row['Field'].'\');'.PHP_EOL;
                    $body .= 'if(empty($value)) { '.PHP_EOL;
                    $body .= '    return null;'.PHP_EOL;
                    $body .= '}'.PHP_EOL;
                    $body .= PHP_EOL;
                    $body .= 'return (Uuid::fromBytes($value))->toString();';

                    $cClass->addMethod('get'.$name.'AsString')
                        ->setPublic()
                        ->addComment('@return string|null')
                        ->setBody($body);
                }
            }

            foreach ($fields as $i => $row) {
                $fieldConfig = $config['fields'][$row['Field']];
                if ($fieldConfig['setter_ignore']) {
                    continue;
                }

                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';

                if ($fieldConfig['type_hint'] != '') {
                    $return_type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }

                $cSetter = $cClass->addMethod('set'.$name);
                $cSetter->addComment('@param '.$return_type.' $value');
                $cSetter->addComment('@return static');
                $cSetter->addParameter('value')->setType($return_type == 'mixed' ? '' : $return_type);
                $cSetter->setVisibility($fieldConfig['visibility'] ?? 'public');

                $body = '';

                if ($fieldConfig['uuid_field']) {
                    $body = <<<'NOW'
if(!empty($value)) {
    if(\strlen($value) === 36) {
        $value = Uuid::fromString($value)->getBytes();
    }
    try {
        Uuid::fromBytes($value);
    } catch (\Exception) {
        throw new \GCWorld\ORM\Exceptions\UuidException('Invalid UUID Set');
    }
}
NOW;
                }
                $body .= PHP_EOL;
                if (isset($fieldConfig['backed_enum']) && $fieldConfig['backed_enum']) {
                    $body .= 'return $this->set(\''.$row['Field'].'\', $value->value);'.PHP_EOL;
                } else {
                    $body .= 'return $this->set(\''.$row['Field'].'\', $value);';
                }

                $cSetter->setBody($body);
            }
        }

        if ($this->json_serialize) {
            $cMethod = $cClass->addMethod('jsonSerialize');
            $cMethod->addComment('@return array');
            $cMethod->setReturnType('mixed');

            $body = 'return ['.PHP_EOL;

            foreach ($fields as $i => $row) {
                $fName       = $row['Field'];
                $fieldConfig = $config['fields'][$fName] ?? Config::getDefaultFieldConfig();
                if ($this->get_set_funcs) {
                    $name = FieldName::getterName($fName);
                    if ($fieldConfig['uuid_field']) {
                        $body .= "    '$fName' => ".'$this->'.$name.'AsString(),'.PHP_EOL;
                        continue;
                    }
                    $body .= "    '$fName' => ".'$this->'.$name.'(),'.PHP_EOL;
                    continue;
                }
                $body .= "    '$fName' => ".'$this->'.$fName.','.PHP_EOL;
            }
            $body .= '];';
            $cMethod->setBody($body);
        }

        if ($save_hook) {
            $cMethod = $cClass->addMethod('saveHook');
            $cMethod->addComment('@param array $before');
            $cMethod->addComment('@param array $after');
            $cMethod->addComment('@param array $changed');
            $cMethod->addParameter('before')->setType('array');
            $cMethod->addParameter('after')->setType('array');
            $cMethod->addParameter('changed')->setType('array');
            $cMethod->setVisibility('protected');
            $body  = '$table_name    = constant($this->myName.\'::CLASS_TABLE\');'.PHP_EOL;
            $body .= '$primary_name  = constant($this->myName.\'::CLASS_PRIMARY\');'.PHP_EOL;
            $body .= '$primary_id    = $this->$primary_name;'.PHP_EOL;
            $body .= PHP_EOL;
            $body .= $save_hook_call.'($table_name, $primary_id, $before, $after, $changed);';
            $cMethod->setBody($body);
        }

        if(!empty($enums)) {
            $cClass->addConstant('ORM_ENUMS', $enums);
        }

        // Not for traits
        $this->logger->debug('ACTION: Calling doFactory');
        $this->doFactory($cClass, $cNamespace, $config['fields']);
        $this->logger->debug('ACTION: Calling doBaseExceptions');
        $this->doBaseExceptions($cClass, $cNamespace, $config['fields']);

        if (isset($this->config['descriptions'])
            && isset($this->config['descriptions']['enabled'])
            && $this->config['descriptions']['enabled']
            && isset($this->config['descriptions']['desc_dir'])
            && !empty($this->config['descriptions']['desc_dir'])
        ) {
            $tmp = explode(DIRECTORY_SEPARATOR, $this->cConfig->getConfigFilePath());
            array_pop($tmp);
            $startPath = implode(DIRECTORY_SEPARATOR, $tmp).DIRECTORY_SEPARATOR;
            $descDir   = $startPath.$this->config['descriptions']['desc_dir'];
            if (!is_dir($descDir)) {
                $this->logger->alert('Descriptions: Desc Dir is defined but cannot be found: '.$descDir);
            } else {
                $this->doDescription($cClass, $cNamespace, $descDir, $table_name, $dbInfo);
            }
        }

        $cPrinter  = new PsrPrinter();
        $contents  = '<?php'.PHP_EOL;
        $contents .= $cPrinter->printNamespace($cNamespace);

        $this->logger->info('DISK ACCESS: Writing File: '.$path.$filename);
        file_put_contents($path.$filename, $contents);


        $this->logger->info('ACTION: Generating Trait');
        //Create a trait version
        $path     = $this->master_location.DIRECTORY_SEPARATOR.'Generated/Traits/';
        $filename = $table_name.'.php';
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        $cTraitNamespace = new PhpNamespace('GCWorld\\ORM\\Generated\\Traits');
        $cTraitClass     = $cTraitNamespace->addTrait($table_name);

        foreach ($fields as $i => $row) {
            if (in_array($row['Field'], $primaries)) {
                continue;
            }
            $type = (stristr($row['Type'], 'int') ? 'int   ' : 'string');

            $cProperty = $cTraitClass->addProperty($row['Field']);
            $cProperty->addComment('@var '.$type);
            $cProperty->addComment('@db-info '.$row['Type']);
            $cProperty->setVisibility($this->var_visibility);
            $cProperty->setValue($this->use_defaults ? $this->formatDefault($row) : null);
        }

        if ($this->get_set_funcs || $this->var_visibility == 'protected') {
            foreach ($fields as $i => $row) {
                if (in_array($row['Field'], $primaries)) {
                    continue;
                }
                $fieldConfig = $config['fields'][$row['Field']] ?? Config::getDefaultFieldConfig();
                if ($fieldConfig['getter_ignore']) {
                    continue;
                }

                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';

                if ($fieldConfig['type_hint'] != '') {
                    $return_type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }

                $cMethod = $cTraitClass->addMethod('get'.$name);
                $cMethod->addComment('@return '.$return_type);
                $cMethod->setBody('return $this->'.$row['Field'].';');

                if ($fieldConfig['uuid_field']) {
                    $cMethod = $cTraitClass->addMethod('get'.$name.'AsString');
                    $cMethod->addComment('@return string');
                    $body  = '$value = $this->get(\''.$row['Field']."');".PHP_EOL;
                    $body .= 'if(empty($value)) {'.PHP_EOL;
                    $body .= '    return \'\';'.PHP_EOL;
                    $body .= '}'.PHP_EOL.PHP_EOL;
                    $body .= 'return (Uuid::fromBytes($value))->toString();'.PHP_EOL;
                    $cMethod->setBody($body);
                }
            }
        }

        $cPrinter  = new PsrPrinter();
        $contents  = '<?php'.PHP_EOL;
        $contents .= $cPrinter->printNamespace($cTraitNamespace);

        $this->logger->info('DISK ACCESS: Writing File: '.$path.$filename);
        file_put_contents($path.$filename, $contents);

        return true;
    }

    public function getKeys(string $table_name): array
    {
        $sql   = 'SHOW INDEX FROM '.$table_name;
        $query = $this->master_common->getDatabase()->prepare($sql);
        $query->execute();
        $indexes = $query->fetchAll(PDO::FETCH_ASSOC);
        unset($query);
        $uniques = [];
        $primary = null;

        // Factory Stuff
        if (count($indexes) < 1) {
            return [
                'uniques' => $uniques,
                'primary' => $primary,
            ];
        }

        foreach ($indexes as $v) {
            if ($v['Non_unique']) {
                continue;
            }
            if ($v['Key_name'] == 'PRIMARY') {
                $primary = $v['Column_name'];
                continue;
            }
            if (!isset($uniques[$v['Key_name']])) {
                $uniques[$v['Key_name']] = [];
            }
            $uniques[$v['Key_name']][] = $v;
        }

        if ($primary === null || empty($uniques)) {
            return [
                'uniques' => $uniques,
                'primary' => $primary,
            ];
        }

        foreach ($uniques as $k => $v) {
            if (count($v) < 2) {
                unset($uniques[$k]);
                continue;
            }

            uasort($v, function ($a, $b) {
                return $a['Seq_in_index'] <=> $b['Seq_in_index'];
            });
            $uniques[$k] = $v;
        }

        return [
            'uniques' => $uniques,
            'primary' => $primary,
        ];
    }

    /**
     * @param ClassType $cClass
     * @param PhpNamespace $cNamespace
     * @param array<string,mixed> $fields
     */
    protected function doFactory(ClassType $cClass, PhpNamespace $cNamespace, array $fields): void
    {
        $keys    = $this->getKeys($cClass->getName());
        $uniques = $keys['uniques'];
        $primary = $keys['primary'];

        $this->logger->debug('Discovered Uniques', $uniques);

        // We don't have a primary key.  That can't be good.
        if ($primary == null) {
            return;
        }

        if (count($uniques) > 0) {
            $cNamespace->addUse('GCWorld\\ORM\\CommonLoader');
        }

        foreach ($uniques as $key => $unique) {
            $this->logger->debug('Processing Unique: '.$key);
            $name   = str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
            $vars   = [];
            $varStr = [];

            // Factory All Method =====================================================================================
            $cMethod = $cClass->addMethod('factory'.$name.'All');
            $this->logger->debug('Creating Method: '.$cMethod->getName());
            $cMethod->setPublic();
            $cMethod->setStatic(true);
            $cMethod->addComment('@return static');
            foreach ($unique as $item) {
                $fieldConfig = $fields[$item['Column_name']] ?? Config::getDefaultFieldConfig();
                $type        = 'mixed';
                if ($fieldConfig['type_hint'] != '') {
                    $type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $type = $this->defaultReturn($item['Type']);
                }

                if (isset($item['Null']) && strtoupper($item['Null']) == 'YES') {
                    $cMethod->addComment('@param ?'.$type.' '.$item['Column_name']);
                    $cMethod->addParameter($item['Column_name'], null);
                } else {
                    $cMethod->addComment('@param '.$type.' '.$item['Column_name']);
                    $cMethod->addParameter($item['Column_name']);
                }

                $vars[]   = $item['Column_name'];
                $varStr[] = '$'.$item['Column_name'];
            }

            $str   = implode(', ', $varStr);
            $body  = '$id = self::find'.$name.'('.$str.');'.PHP_EOL;
            $body .= 'if(!empty($id)) {'.PHP_EOL;
            $body .= '    return new static($id);'.PHP_EOL;
            $body .= '}'.PHP_EOL.PHP_EOL;
            $body .= '$cObj = new static();'.PHP_EOL;
            foreach ($vars as $var) {
                $setter = FieldName::setterName($var);
                $body  .= '$cObj->'.$setter.'($'.$var.');'.PHP_EOL;
            }
            $body .= PHP_EOL;
            $body .= 'return $cObj;'.PHP_EOL;
            $cMethod->setBody($body);

            // Factory ID Method ======================================================================================
            $cMethod = $cClass->addMethod('factory'.$name);
            $this->logger->debug('Creating Method: '.$cMethod->getName());
            $cMethod->setPublic();
            $cMethod->setStatic(true);
            $cMethod->addParameter($primary);
            $cMethod->addComment('@param mixed $'.$primary);
            $cMethod->addComment('@return static');

            $body  = 'if(empty($'.$primary.')) {'.PHP_EOL;
            $body .= '    throw new \\Exception(\'Primary cannot be empty\');'.PHP_EOL;
            $body .= '}'.PHP_EOL.PHP_EOL;
            $body .= 'return new static($'.$primary.');'.PHP_EOL;
            $cMethod->setBody($body);

            // Find Primary Function ==================================================================================
            $cMethod = $cClass->addMethod('find'.$name);
            $this->logger->debug('Creating Method: '.$cMethod->getName());
            $cMethod->setPublic();
            $cMethod->setStatic(true);
            $cMethod->addComment('@return mixed');
            foreach ($unique as $item) {
                $fieldConfig = $fields[$item['Column_name']] ?? Config::getDefaultFieldConfig();
                $type        = 'mixed';
                if ($fieldConfig['type_hint'] != '') {
                    $type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $type = $this->defaultReturn($item['Type']);
                }

                $cMethod->addComment('@param mixed $'.$item['Column_name']);
                $cMethod->addParameter($item['Column_name'])->setType($type);
            }

            $params = [];
            $where  = [];
            foreach ($unique as $item) {
                $var     = $item['Column_name'];
                $enum    = isset($fields[$var]['backed_enum']) && isset($fields[$var]['backed_enum']);
                $where[] = $var.' = :'.$var;
                if ($enum) {
                    $params[] = '\':' . $var . '\' => $' . $var . '->value,' . PHP_EOL;
                } else {
                    $params[] = '\':' . $var . '\' => $' . $var . ',' . PHP_EOL;
                }
            }
            $sWhere = 'WHERE '.implode(' AND ', $where);

            $body  = '$sql   = \'SELECT '.$primary.' FROM '.$cClass->getName().PHP_EOL;
            $body .= '          '.$sWhere.'\';'.PHP_EOL;
            $body .= '$query = CommonLoader::getCommon()->getDatabase()->prepare($sql);'.PHP_EOL;
            $body .= '$query->execute(['.PHP_EOL;
            foreach ($params as $param) {
                $body .= '    '.$param;
            }
            $body .= ']);'.PHP_EOL;
            $body .= '$row = $query->fetch();'.PHP_EOL;
            $body .= '$query->closeCursor();'.PHP_EOL;
            $body .= 'unset($query);'.PHP_EOL;
            $body .= 'if($row) {'.PHP_EOL;
            $body .= '    return $row[\''.$primary.'\'];'.PHP_EOL;
            $body .= '}'.PHP_EOL.PHP_EOL;
            $body .= 'return null;'.PHP_EOL;
            $cMethod->setBody($body);
        }
    }

    /**
     * @param ClassType $cClass
     * @param PhpNamespace $cNamespace
     * @param array<string,mixed> $fields
     */
    protected function doBaseExceptions(ClassType $cClass, PhpNamespace $cNamespace, array $fields): void
    {
        $columns = [];
        foreach ($fields as $id => $field) {
            if (isset($field['required']) && $field['required']) {
                $columns[] = $id;
            }
        }

        $keys    = $this->getKeys($cClass->getName());
        $uniques = $keys['uniques'];
        $primary = $keys['primary'];

        if (count($columns) < 1) {
            foreach ($uniques as $unique) {
                foreach ($unique as $item) {
                    $columns[] = $item['Column_name'];
                }
            }
            foreach ($fields as $key => $field) {
                if (isset($field['visibility']) && $field['visibility'] == 'protected') {
                    $columns[] = $key;
                }
            }

            $columns = array_unique($columns);
        }

        if (count($columns) < 1) {
            return;
        }
        sort($columns);

        $cNamespace->addUse('GCWorld\\ORM\\Exceptions\\ModelSaveExceptions');
        $cNamespace->addUse('GCWorld\\ORM\\Exceptions\\ModelRequiredFieldException');

        $uuid_fields = false;
        foreach ($columns as $k => $column) {
            if ($column == $primary) {
                unset($columns[$k]);
                continue;
            }
            if (isset($fields[$column]['uuid_field']) && $fields[$column]['uuid_field']) {
                $uuid_fields = true;
            }
        }

        $cMethod = $cClass->addMethod('saveTest');
        $cMethod->addComment('@return void');
        $cMethod->addComment('@throws ModelSaveExceptions');
        $cMethod->setPublic();

        $body = '$cExceptions = new ModelSaveExceptions();'.PHP_EOL;
        foreach ($columns as $column) {
            $body .= 'if(empty($this->'.$column.')) {'.PHP_EOL;
            $body .= '    $cExceptions->addException(new ModelRequiredFieldException(\''.$column.'\'));'.PHP_EOL;
            $body .= '}'.PHP_EOL;
        }
        $body .= 'if($cExceptions->isThrowable()){'.PHP_EOL;
        $body .= '    throw $cExceptions;'.PHP_EOL;
        $body .= '}'.PHP_EOL.PHP_EOL;

        if ($uuid_fields) {
            $cNamespace->addUse('GCWorld\\ORM\\Exceptions\\ModelInvalidUUIDFormatException');
            $cNamespace->addUse('Exception');

            foreach ($columns as $column) {
                if (!isset($fields[$column]['uuid_field']) || !$fields[$column]['uuid_field']) {
                    continue;
                }

                $body .= 'try {'.PHP_EOL;
                $body .= '    Uuid::fromBytes($this->'.$column.');'.PHP_EOL;
                $body .= '} catch (Exception $e) {'.PHP_EOL;
                $body .= '    $cExceptions->addException(new ModelInvalidUUIDFormatException(\''.$column.'\'));'.PHP_EOL;
                $body .= '}'.PHP_EOL;
            }
            $body .= 'if($cExceptions->isThrowable()){'.PHP_EOL;
            $body .= '    throw $cExceptions;'.PHP_EOL;
            $body .= '}'.PHP_EOL.PHP_EOL;
        }

        $cMethod->setBody($body);
    }

    public function load(): object
    {
        $args       = func_get_args();
        $class_name = '\\GCWorld\\ORM\\Generated\\'.$args[0];

        if (!class_exists($class_name)) {
            die('Invalid Class: '.$class_name);
        }
        $args[0] = $this->master_common;

        $reflectionClass = new ReflectionClass($class_name);
        return $reflectionClass->newInstanceArgs($args);
    }

    /**
     * @param array<string,mixed> $row
     */
    protected function formatDefault(array $row): mixed
    {
        $default = $row['Default'];
        if ($default === null) {
            if ($row['Null'] == 'NO') {
                $default = $this->defaultData($row['Type']);
            }
        } elseif (strtoupper($default) == 'CURRENT_TIMESTAMP') {
            $default = '0000-00-00 00:00:00';
        }

        if (is_numeric($default)) {
            if (strstr($default, '.')) {
                return floatval($default);
            }

            return intval($default);
        }

        return $default;
    }

    protected function defaultData(string $type): mixed
    {
        $type = strtoupper($type);
        $pos  = strpos($type, '(');
        if ($pos > 0) {
            $type = substr($type, 0, $pos);
        }

        switch ($type) {
            case 'INTEGER':
            case 'TINYINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'INT':
            case 'BOOLEAN':
            case 'BIGINT':
            case 'SERIAL':
                return 0;


            case 'DECIMAL':
            case 'FLOAT':
            case 'DOUBLE':
            case 'REAL':
            case 'BIT':
            case 'NUMERIC':
            case 'YEAR':
                return 0.0;


            case 'DATE':
                return '0000-00-00';


            case 'DATETIME':
            case 'TIMESTAMP':
                return '0000-00-00 00:00:00';


            case 'TIME':
                return '00:00:00';


            case 'CHAR':
            case 'VARCHAR':
            case 'TINYTEXT':
            case 'TEXT':
            case 'MEDIUMTEXT':
            case 'LONGTEXT':
            case 'BINARY':
            case 'VARBINARY':
            case 'TINYBLOB':
            case 'MEDIUMBLOB':
            case 'BLOB':
            case 'LONGBLOB':
            case 'ENUM':
            case 'SET':
                return '';


            case 'JSON':
                return '{}';  // Probably not necessary, but hey, stay safe
        }

        // Ignoring geometry, because fuck that.
        return null;
    }

    protected function defaultReturn(string $type): string
    {
        $type = strtoupper($type);
        $pos  = strpos($type, '(');
        if ($pos > 0) {
            $type = substr($type, 0, $pos);
        }

        switch ($type) {
            case 'INTEGER':
            case 'TINYINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'INT':
            case 'BIGINT':
            case 'SERIAL':
            case 'NUMERIC':
                return 'int';

            case 'BOOLEAN':
                return 'bool';

            case 'DECIMAL':
            case 'FLOAT':
            case 'DOUBLE':
            case 'REAL':
            case 'BIT':
            case 'YEAR':
                return 'float';

            case 'DATE':
            case 'DATETIME':
            case 'TIMESTAMP':
            case 'TIME':
            case 'CHAR':
            case 'VARCHAR':
            case 'TINYTEXT':
            case 'TEXT':
            case 'MEDIUMTEXT':
            case 'LONGTEXT':
            case 'BINARY':
            case 'VARBINARY':
            case 'TINYBLOB':
            case 'MEDIUMBLOB':
            case 'BLOB':
            case 'LONGBLOB':
            case 'ENUM':
            case 'SET':
            case 'JSON':
                return 'string';
        }

        // Ignoring geometry, because fuck that.
        return 'mixed';
    }

    /**
     * @param ClassType $cClass
     * @param PhpNamespace $cNamespace
     * @param array<string,mixed> $dbInfo
     */
    protected function doDescription(
        ClassType $cClass,
        PhpNamespace $cNamespace,
        string $descDir,
        string $table_name,
        array $dbInfo
    ): void {
        //Create a trait version
        $existing = [];
        $changed  = false;
        $descFile = $descDir.$table_name.'.yml';
        if (file_exists($descFile)) {
            $existing = Yaml::parseFile($descFile);
            if (!is_array($existing)) {
                $existing = [];
            }
        }

        foreach ($dbInfo as $field => $techDesc) {
            if (!isset($existing[$field])) {
                $changed          = true;
                $existing[$field] = [
                    'title'  => $field,
                    'desc'   => '',
                    'help'   => '',
                    'tech'   => $techDesc,
                    'maxlen' => $this->determineMaxLen($techDesc),
                ];
                continue;
            }
            if (!isset($existing[$field]['title'])) {
                $changed                   = true;
                $existing[$field]['title'] = $field;
            }
            if (!isset($existing[$field]['desc'])) {
                $changed                  = true;
                $existing[$field]['desc'] = '';
            }
            if (!isset($existing[$field]['help'])) {
                $changed                  = true;
                $existing[$field]['help'] = '';
            }
            if (!isset($existing[$field]['tech'])) {
                $changed                  = true;
                $existing[$field]['tech'] = $techDesc;
            }
            if (!isset($existing[$field]['maxlen'])) {
                $changed                    = true;
                $existing[$field]['maxlen'] = $this->determineMaxLen($techDesc);
            }
        }

        if ($changed) {
            file_put_contents($descFile, Yaml::dump($existing, 4));
        }

        $odiTrait = 'GCWorld\\ORM\\Traits\\ORMFieldsTrait';
        if (isset($this->config['descriptions']['desc_trait'])
            && !empty($this->config['descriptions']['desc_trait'])
            && trait_exists($this->config['descriptions']['desc_trait'])
        ) {
            $odiTrait = $this->config['descriptions']['desc_trait'];
        }

        $cNamespace->addUse('GCWorld\\Interfaces\\ORMDescriptionInterface');
        $cNamespace->addUse($odiTrait);
        $cClass->addImplement('GCWorld\\Interfaces\\ORMDescriptionInterface');
        $cClass->addTrait($odiTrait);

        $cProperty = $cClass->addProperty('ORM_FIELDS');
        $cProperty->setVisibility('public');
        $cProperty->setStatic(true);
        $cProperty->setType('array');
        $cProperty->addComment('@var array');
        $cProperty->setValue($existing);
    }

    protected function determineMaxLen(string $techDesc): int
    {
        $start = strpos($techDesc, '(');
        if ($start === false) {
            return 0;
        }
        $end = strpos($techDesc, ')', $start + 1);
        if ($end === false) {
            return 0;
        }

        $length = $end - $start;
        $result = substr($techDesc, $start + 1, $length - 1);
        return intval($result);
    }
}

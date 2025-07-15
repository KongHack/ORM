<?php
namespace GCWorld\ORM;

use BackedEnum;
use Exception;
use GCWorld\Interfaces\CommonInterface;
use GCWorld\Interfaces\Database\DatabaseInterface;
use Monolog\Logger;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\PsrPrinter;
use PDO;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Core.
 */
class Core
{
    protected Config $cConfig;

    /** @var CommonInterface|\GCWorld\Common\Common */
    protected mixed   $master_common    = null;
    protected string  $master_namespace = '\\';
    protected ?string $master_location  = null;
    protected ?string $sub_namespace    = null;
    protected array   $config           = [];
    protected Logger  $logger;
    protected DatabaseInterface $_db;

    protected string  $var_visibility         = 'public';
    protected bool    $get_set_funcs          = true;
    protected bool    $json_serialize         = true;
    protected bool    $use_defaults           = true;
    protected bool    $defaults_override_null = true;
    protected bool    $type_hinting           = false;
    protected bool    $audit                  = true;

    /**
     * @param string          $namespace
     * @param CommonInterface $common
     * @param Config|null     $cConfig
     */
    public function __construct(string $namespace, CommonInterface $common, ?Config $cConfig = new Config())
    {
        $this->master_namespace = $namespace;
        $this->master_common    = $common;
        $this->master_location  = __DIR__;

        $this->cConfig = $cConfig;
        $config        = $cConfig->getConfig();
        $this->config  = $config;

        $this->_db = $common->getDatabase($config['general']['database_name'] ?? 'default');

        if (isset($config['general']['audit']) && !$config['general']['audit']) {
            $this->audit = false;
        }
        if (!empty($config['general']['sub_namespace'])) {
            $this->sub_namespace = $config['general']['sub_namespace'];
        }

        if (isset($config['options']['get_set_funcs'])) {
            if (!$config['options']['get_set_funcs']) {
                $this->get_set_funcs = false;
            }
        }

        if (isset($config['options']['var_visibility'])
            && \in_array($config['options']['var_visibility'], ['public', 'protected'])
        ) {
            $this->var_visibility = $config['options']['var_visibility'];
        }

        if (isset($config['options']['json_serialize']) && !$config['options']['json_serialize']) {
            $this->json_serialize = false;
        }
        if (isset($config['options']['use_defaults']) && !$config['options']['use_defaults']) {
            $this->use_defaults = false;
        }
        if (isset($config['options']['defaults_override_null']) && !$config['options']['defaults_override_null']) {
            $this->defaults_override_null = false;
        }
        if (isset($config['options']['type_hinting']) && $config['options']['type_hinting']) {
            $this->type_hinting = true;
        }

        $this->logger = new Logger('orm_core');
    }

    /**
     * @param Logger $logger
     *
     * @return void
     */
    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @return Logger|null
     */
    public function getLogger(): ?Logger
    {
        return $this->logger;
    }

    /**
     * @param string $table_name
     *
     * @throws Exception
     *
     * @return bool
     */
    public function generate(string $table_name): bool
    {
        $this->logger->info('Processing Table: '.$table_name);

        $sql   = 'SHOW FULL COLUMNS FROM '.$table_name;
        $query = $this->_db->prepare($sql);
        $query->execute();
        $fields = $query->fetchAll(PDO::FETCH_ASSOC);
        unset($query);
        $config = $this->config['tables'][$table_name] ?? [];

        $this->logger->debug('Found Fields', $fields);
        $this->logger->debug('Found Config', $config);

        $config['constructor'] ??= 'public';
        $config['audit_ignore'] ??= false;
        $config['fields'] ??= [];
        $config['cache_ttl'] ??= 0;
        $config['audit_handler'] = ($config['audit_handler'] ?? ($this->config['general']['audit_handler'] ?? null));

        $save_hook      = isset($config['save_hook']);
        $save_hook_call = $config['save_hook'] ?? '';
        if ($save_hook && empty($save_hook_call)) {
            $save_hook = false;
        }

        $cache_after_purge = $config['cache_after_purge'] ?? null;
        if (null === $cache_after_purge) {
            $cache_after_purge = $this->config['options']['cache_after_purge'] ?? false;
        }

        $uuid_fields    = false;
        $auto_increment = false;
        $primaries      = [];
        $max_var_name   = 0;
        $max_var_type   = 0;

        $path = $this->master_location.DIRECTORY_SEPARATOR.'Generated'.DIRECTORY_SEPARATOR;
        if (!\is_dir($path)) {
            \mkdir($path, 0o755, true);
        }
        if (!empty($this->sub_namespace)) {
            $path .= $this->sub_namespace.DIRECTORY_SEPARATOR;
            if (!\is_dir($path)) {
                \mkdir($path, 0o755, true);
            }
        }

        $default = Config::getDefaultFieldConfig();
        foreach ($fields as $i => $row) {
            $this->logger->debug('Processing Field', $row);

            // Do not include virtual fields in the system
            if (false !== \stripos($row['Extra'], 'VIRTUAL')) {
                $this->logger->debug('Unsetting Virtual: '.$row['Field']);
                unset($fields[$i]);

                continue;
            }

            if (\stristr($row['Key'], 'PRI')) {
                $primaries[] = $row['Field'];
            }
            if (\strlen($row['Field']) > $max_var_name) {
                $max_var_name = \strlen($row['Field']);
            }
            if (\strlen($row['Type']) > $max_var_type) {
                $max_var_type = \strlen($row['Type']);
            }
            if (\stristr($row['Extra'], 'auto_increment')) {
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
                    false !== \stripos($row['Field'], '_uuid')
                    && 'binary(16)' == \strtolower($row['Type'])
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
                && \str_contains($config['fields'][$row['Field']]['type_hint'], '\\')
                && \enum_exists($config['fields'][$row['Field']]['type_hint'])
            ) {
                $cReflection = new ReflectionClass($config['fields'][$row['Field']]['type_hint']);
                $config['fields'][$row['Field']]['backed_enum'] = $cReflection->implementsInterface(BackedEnum::class);
            }
        }

        $this->logger->debug('Discovered Primaries', $primaries);

        if (1 !== \count($primaries)) {
            return false;
        }

        $this->logger->debug('Generating Code');

        $baseNamespace = 'GCWorld\\ORM\\Generated';
        if (!empty($this->sub_namespace)) {
            $baseNamespace .= '\\'.$this->sub_namespace;
        }

        $filename   = $table_name.'.php';
        $cNamespace = new PhpNamespace($baseNamespace);
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
            $type = (\stristr($row['Type'], 'int') ? 'int   ' : 'string');
            if ('YES' == $row['Null']) {
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
            $dbInfo[$row['Field']] = $row['Type'].('' != $row['Comment'] ? ' - '.$row['Comment'] : '');
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

                if ('' != $fieldConfig['type_hint']) {
                    $return_type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }

                $cMethod = $cClass->addMethod('get'.$name);
                $cMethod->setPublic();
                $cMethod->addComment('@return '.('YES' == $row['Null'] ? '?' : '').$return_type);

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

                if ('' != $fieldConfig['type_hint']) {
                    $return_type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $return_type = $this->defaultReturn($row['Type']);
                }

                $cSetter = $cClass->addMethod('set'.$name);
                $cSetter->addComment('@param '.$return_type.' $value');
                $cSetter->addComment('@return static');
                $cSetter->addParameter('value')->setType('mixed' == $return_type ? '' : $return_type);
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
                        $body .= "    '{$fName}' => ".'$this->'.$name.'AsString(),'.PHP_EOL;

                        continue;
                    }
                    $body .= "    '{$fName}' => ".'$this->'.$name.'(),'.PHP_EOL;

                    continue;
                }
                $body .= "    '{$fName}' => ".'$this->'.$fName.','.PHP_EOL;
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

        if (!empty($enums)) {
            $cClass->addConstant('ORM_ENUMS', $enums);
        }

        // Not for traits
        $this->logger->debug('ACTION: Calling doFactory');
        $this->doFactory($cClass, $cNamespace, $config['fields']);
        $this->logger->debug('ACTION: Calling doBaseExceptions');
        $this->doBaseExceptions($cClass, $cNamespace, $config['fields']);

        if (isset($this->config['descriptions'], $this->config['descriptions']['enabled'])
            && $this->config['descriptions']['enabled']
            && isset($this->config['descriptions']['desc_dir'])
            && !empty($this->config['descriptions']['desc_dir'])
        ) {
            $tmp = \explode(DIRECTORY_SEPARATOR, $this->cConfig->getConfigFilePath());
            \array_pop($tmp);
            $startPath = \implode(DIRECTORY_SEPARATOR, $tmp).DIRECTORY_SEPARATOR;
            $descDir   = $startPath.$this->config['descriptions']['desc_dir'];
            if (!\is_dir($descDir)) {
                $this->logger->alert('Descriptions: Desc Dir is defined but cannot be found: '.$descDir);
            } else {
                $this->doDescription($cClass, $cNamespace, $descDir, $table_name, $dbInfo);
            }
        }

        $cPrinter  = new PsrPrinter();
        $contents  = '<?php'.PHP_EOL;
        $contents .= $cPrinter->printNamespace($cNamespace);

        $this->logger->info('DISK ACCESS: Writing File: '.$path.$filename);
        \file_put_contents($path.$filename, $contents);

        $this->logger->info('ACTION: Generating Trait');
        // Create a trait version
        $path = $this->master_location.DIRECTORY_SEPARATOR.'Generated'.DIRECTORY_SEPARATOR;
        if (!empty($this->sub_namespace)) {
            $path .= $this->sub_namespace.DIRECTORY_SEPARATOR;
        }
        $path    .= 'Traits'.DIRECTORY_SEPARATOR;
        $filename = $table_name.'.php';
        if (!\is_dir($path)) {
            \mkdir($path, 0o755, true);
        }

        $baseNamespace = 'GCWorld\\ORM\\Generated\\';
        if (!empty($this->sub_namespace)) {
            $baseNamespace .= $this->sub_namespace.'\\';
        }
        $baseNamespace .= 'Traits';
        $cTraitNamespace = new PhpNamespace($baseNamespace);
        $cTraitClass     = $cTraitNamespace->addTrait($table_name);

        foreach ($fields as $i => $row) {
            if (\in_array($row['Field'], $primaries)) {
                continue;
            }
            $type = (\stristr($row['Type'], 'int') ? 'int   ' : 'string');

            $cProperty = $cTraitClass->addProperty($row['Field']);
            $cProperty->addComment('@var '.$type);
            $cProperty->addComment('@db-info '.$row['Type']);
            $cProperty->setVisibility($this->var_visibility);
            $cProperty->setValue($this->use_defaults ? $this->formatDefault($row) : null);
        }

        if ($this->get_set_funcs || 'protected' == $this->var_visibility) {
            foreach ($fields as $i => $row) {
                if (\in_array($row['Field'], $primaries)) {
                    continue;
                }
                $fieldConfig = $config['fields'][$row['Field']] ?? Config::getDefaultFieldConfig();
                if ($fieldConfig['getter_ignore']) {
                    continue;
                }

                $name        = FieldName::nameConversion($row['Field']);
                $return_type = 'mixed';

                if ('' != $fieldConfig['type_hint']) {
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
        \file_put_contents($path.$filename, $contents);

        return true;
    }

    /**
     * @param string $table_name
     *
     * @return array
     */
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
        if (\count($indexes) < 1) {
            return [
                'uniques' => $uniques,
                'primary' => $primary,
            ];
        }

        foreach ($indexes as $v) {
            if ($v['Non_unique']) {
                continue;
            }
            if ('PRIMARY' == $v['Key_name']) {
                $primary = $v['Column_name'];

                continue;
            }
            if (!isset($uniques[$v['Key_name']])) {
                $uniques[$v['Key_name']] = [];
            }
            $uniques[$v['Key_name']][] = $v;
        }

        if (null === $primary || empty($uniques)) {
            return [
                'uniques' => $uniques,
                'primary' => $primary,
            ];
        }

        foreach ($uniques as $k => $v) {
            if (\count($v) < 2) {
                unset($uniques[$k]);

                continue;
            }

            \uasort($v, function ($a, $b) {
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
     * @return object
     */
    public function load()
    {
        $args       = \func_get_args();
        $class_name = '\\GCWorld\\ORM\\Generated\\'.$args[0];

        if (!\class_exists($class_name)) {
            die('Invalid Class: '.$class_name);
        }
        $args[0] = $this->master_common;

        $reflectionClass = new ReflectionClass($class_name);

        return $reflectionClass->newInstanceArgs($args);
    }

    /**
     * @param ClassType    $cClass
     * @param PhpNamespace $cNamespace
     * @param array        $fields
     *
     * @return void
     */
    protected function doFactory(ClassType $cClass, PhpNamespace $cNamespace, array $fields): void
    {
        $keys    = $this->getKeys($cClass->getName());
        $uniques = $keys['uniques'];
        $primary = $keys['primary'];

        $this->logger->debug('Discovered Uniques', $uniques);

        // We don't have a primary key.  That can't be good.
        if (null == $primary) {
            return;
        }

        if (\count($uniques) > 0) {
            $cNamespace->addUse('GCWorld\\ORM\\CommonLoader');
        }

        foreach ($uniques as $key => $unique) {
            $this->logger->debug('Processing Unique: '.$key);
            $name   = \str_replace(' ', '', \ucwords(\str_replace('_', ' ', $key)));
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
                if ('' != $fieldConfig['type_hint']) {
                    $type = $fieldConfig['type_hint'];
                } elseif ($this->type_hinting) {
                    $type = $this->defaultReturn($item['Type']);
                }

                if (isset($item['Null']) && 'YES' == \strtoupper($item['Null'])) {
                    $cMethod->addComment('@param ?'.$type.' '.$item['Column_name']);
                    $cMethod->addParameter($item['Column_name'], null);
                } else {
                    $cMethod->addComment('@param '.$type.' '.$item['Column_name']);
                    $cMethod->addParameter($item['Column_name']);
                }

                $vars[]   = $item['Column_name'];
                $varStr[] = '$'.$item['Column_name'];
            }

            $str   = \implode(', ', $varStr);
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
                if ('' != $fieldConfig['type_hint']) {
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
                $enum    = isset($fields[$var]['backed_enum'], $fields[$var]['backed_enum']);
                $where[] = $var.' = :'.$var;
                if ($enum) {
                    $params[] = '\':'.$var.'\' => $'.$var.'->value,'.PHP_EOL;
                } else {
                    $params[] = '\':'.$var.'\' => $'.$var.','.PHP_EOL;
                }
            }
            $sWhere = 'WHERE '.\implode(' AND ', $where);

            $body  = '$sql   = \'SELECT '.$primary.' FROM '.$cClass->getName().PHP_EOL;
            $body .= '          '.$sWhere.'\';'.PHP_EOL;
            $body .= '$query = CommonLoader::getCommon()->getDatabase(';
            $body .= $this->config['general']['database_name'] ?? 'default';
            $body .= ')->prepare($sql);'.PHP_EOL;
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
     * @param ClassType    $cClass
     * @param PhpNamespace $cNamespace
     * @param array        $fields
     *
     * @return void
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

        if (\count($columns) < 1) {
            foreach ($uniques as $unique) {
                foreach ($unique as $item) {
                    $columns[] = $item['Column_name'];
                }
            }
            foreach ($fields as $key => $field) {
                if (isset($field['visibility']) && 'protected' == $field['visibility']) {
                    $columns[] = $key;
                }
            }

            $columns = \array_unique($columns);
        }

        if (\count($columns) < 1) {
            return;
        }
        \sort($columns);

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

    /**
     * @param array $row
     *
     * @return float|int|mixed
     */
    protected function formatDefault(array $row)
    {
        $default = $row['Default'];
        if (null === $default) {
            if ('NO' == $row['Null']) {
                $default = $this->defaultData($row['Type']);
            }
        } elseif ('CURRENT_TIMESTAMP' == \strtoupper($default)) {
            $default = '0000-00-00 00:00:00';
        }

        if (\is_numeric($default)) {
            if (\strstr($default, '.')) {
                return \floatval($default);
            }

            return \intval($default);
        }

        return $default;
    }

    /**
     * @param mixed $type
     *
     * @return mixed
     */
    protected function defaultData($type)
    {
        $type = \strtoupper($type);
        $pos  = \strpos($type, '(');
        if ($pos > 0) {
            $type = \substr($type, 0, $pos);
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

    /**
     * @param string $type
     *
     * @return string
     */
    protected function defaultReturn(string $type)
    {
        $type = \strtoupper($type);
        $pos  = \strpos($type, '(');
        if ($pos > 0) {
            $type = \substr($type, 0, $pos);
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
     * @param ClassType    $cClass
     * @param PhpNamespace $cNamespace
     * @param string       $descDir
     * @param string       $table_name
     * @param array        $dbInfo
     *
     * @return void
     */
    protected function doDescription(
        ClassType $cClass,
        PhpNamespace $cNamespace,
        string $descDir,
        string $table_name,
        array $dbInfo
    ) {
        // Create a trait version
        $existing = [];
        $changed  = false;
        $descFile = $descDir.$table_name.'.yml';
        if (\file_exists($descFile)) {
            $existing = Yaml::parseFile($descFile);
            if (!\is_array($existing)) {
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
            \file_put_contents($descFile, Yaml::dump($existing, 4));
        }

        $odiTrait = 'GCWorld\\ORM\\Traits\\ORMFieldsTrait';
        if (isset($this->config['descriptions']['desc_trait'])
            && !empty($this->config['descriptions']['desc_trait'])
            && \trait_exists($this->config['descriptions']['desc_trait'])
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

    /**
     * @param $techDesc
     *
     * @return int
     */
    protected function determineMaxLen($techDesc): int
    {
        $start = \strpos($techDesc, '(');
        if (false === $start) {
            return 0;
        }
        $end = \strpos($techDesc, ')', $start + 1);
        if (false === $end) {
            return 0;
        }

        $length = $end - $start;
        $result = \substr($techDesc, $start + 1, $length - 1);

        return \intval($result);
    }
}

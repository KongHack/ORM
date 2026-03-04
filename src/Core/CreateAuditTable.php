<?php
namespace GCWorld\ORM\Core;

use GCWorld\Interfaces\Database\DatabaseInterface;
use GCWorld\ORM\CommonLoader;
use PDOException;

class CreateAuditTable
{
    /**
     * @var DatabaseInterface|\GCWorld\Database\Database
     */
    protected $_source;
    /**
     * @var DatabaseInterface|\GCWorld\Database\Database
     */
    protected $_destination;
    /**
     * @var array
     */
    protected $config       = [];

    /**
     * CreateAuditTable constructor.
     *
     * @param DatabaseInterface $_source
     * @param DatabaseInterface $_destination
     */
    public function __construct(DatabaseInterface $_source, DatabaseInterface $_destination)
    {
        $this->_source      = $_source;
        $this->_destination = $_destination;
        $this->config       = CommonLoader::getCommon()->getConfig('audit');
    }

    /**
     * @param string $table
     *
     * @return void
     */
    public function buildTable(string $table)
    {
        // / $schema = $this->_source->getWorkingDatabaseName();
        $preLen = \strlen($this->config['prefix']);

        // Prevent recursion
        if ($preLen > 0) {
            if (\substr($table, 0, $preLen) == $this->config['prefix']) {
                return;
            }
        }
        $audit = $this->config['prefix'].$table;

        $source = \file_get_contents(Builder::getDataModelDirectory().'source.sql');
        $sql    = \str_replace('__REPLACE__', $audit, $source);
        $this->_destination->exec($sql);
        $this->_destination->setTableComment($audit, '0');

        $version      = 0;
        $versionFiles = \glob(Builder::getDataModelDirectory().'revisions'.DIRECTORY_SEPARATOR.'*.sql');
        \sort($versionFiles);
        foreach ($versionFiles as $file) {
            $tmp        = \explode(DIRECTORY_SEPARATOR, $file);
            $fileName   = \array_pop($tmp);
            $tmp        = \explode('.', $fileName);
            $fileNumber = \intval($tmp[0]);
            unset($tmp);

            if ($fileNumber > $version && $fileNumber < Builder::BUILDER_VERSION) {
                $model = \file_get_contents($file);
                $sql   = \str_replace('__REPLACE__', $audit, $model);

                try {
                    $this->_destination->exec($sql);
                    $this->_destination->setTableComment($audit, (string) $fileNumber);
                } catch (PDOException $e) {
                    if (\str_contains($e->getMessage(), 'Column already exists')) {
                        continue;
                    }

                    echo 'BAD SQL: ',PHP_EOL,PHP_EOL,$sql,PHP_EOL,PHP_EOL;

                    throw $e;
                }
            }
        }
    }
}

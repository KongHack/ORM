<?php
namespace GCWorld\ORM;

use \Exception;

/**
 * Class ORMException
 * @package GCWorld\ORM
 */
class ORMException extends Exception
{
    public $backtrace = null;

    /**
     * ORMException constructor.
     * @param string          $message
     * @param int             $code
     * @param \Exception|null $previous
     */
    public function __construct($message, $code = 0, Exception $previous = null)
    {
        $this->backtrace = debug_backtrace();
        parent::__construct($message, $code, $previous);
    }

    /**
     * custom string representation of object
     * @return string
     */
    public function __toString()
    {
        return __CLASS__.": [{$this->code}]: {$this->message}\n";
    }
}

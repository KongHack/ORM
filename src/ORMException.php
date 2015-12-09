<?php
namespace GCWorld\ORM;

use \Exception;

class ORMException extends Exception
{
    public $backtrace = null;

    public function __construct($message, $code = 0, Exception $previous = null)
    {
        $this->backtrace = debug_backtrace();
        parent::__construct($message, $code, $previous);
    }

    // custom string representation of object
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}

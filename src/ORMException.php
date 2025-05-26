<?php
namespace GCWorld\ORM;

use \Exception;

/**
 * Class ORMException
 * @package GCWorld\ORM
 */
class ORMException extends Exception
{
    public readonly ?array $backtrace;

    /**
     * ORMException constructor.
     */
    public function __construct(string $message, int $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->backtrace = debug_backtrace();
    }

    /**
     * custom string representation of object
     */
    public function __toString(): string
    {
        return __CLASS__.": [{$this->code}]: {$this->message}\n";
    }

    public function getTrace(): ?array
    {
        return $this->backtrace;
    }
}

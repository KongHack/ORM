<?php

namespace GCWorld\ORM;

use Exception;

/**
 * Class ORMException.
 */
class ORMException extends Exception
{
    /** @var array<mixed> */
    public array $backtrace;

    /**
     * ORMException constructor.
     *
     * @param string         $message
     * @param int            $code
     * @param Exception|null $previous
     */
    public function __construct(string $message, int $code = 0, ?Exception $previous = null)
    {
        $this->backtrace = \debug_backtrace();
        parent::__construct($message, $code, $previous);
    }

    /**
     * custom string representation of object.
     *
     * @return string
     */
    public function __toString()
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }

    /**
     * @return array<mixed>|null
     */
    public function getBacktrace(): ?array
    {
        return $this->backtrace;
    }
}

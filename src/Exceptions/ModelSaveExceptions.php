<?php
namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelSaveExceptions.
 */
class ModelSaveExceptions extends Exception
{
    /**
     * @var Exception[]
     */
    protected $exceptArray = [];

    /**
     * ModelRequiredFieldException constructor.
     *
     * @param string          $message
     * @param int             $code
     * @param Exception|null $previous
     */
    public function __construct(string $message = '', int $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param Exception $e
     *
     * @return $this
     */
    public function addException(Exception $e)
    {
        $this->exceptArray[] = $e;
        $this->message      .= PHP_EOL.$e->getMessage();

        return $this;
    }

    /**
     * @return array
     */
    public function getFieldMessages()
    {
        if (!$this->isThrowable()) {
            return [];
        }

        $messages = [];
        foreach ($this->exceptArray as $k => $e) {
            if ($e instanceof FieldException) {
                $messages[$e->getFieldName()] = $e->getMessage();

                continue;
            }

            $messages['UNDEFINED_'.$k] = $e->getMessage();
        }

        return $messages;
    }

    /**
     * @return bool
     */
    public function isThrowable()
    {
        return \count($this->exceptArray) > 0;
    }
}

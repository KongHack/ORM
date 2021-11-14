<?php

namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;
use GCWorld\ORM\Interfaces\ModelSaveExceptionsInterface;

/**
 * Class ModelSaveExceptions.
 */
class ModelSaveExceptions extends Exception implements ModelSaveExceptionsInterface
{
    /**
     * @var Exception[]
     */
    protected $exceptArray = [];

    /**
     * ModelRequiredFieldException constructor.
     *
     * @param string         $message
     * @param int            $code
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
    public function getFieldMessages(): array
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
    public function isThrowable(): bool
    {
        return !empty($this->exceptArray);
    }

    /**
     * @throws ModelSaveExceptions
     * @return void
     */
    public function doThrow(): void
    {
        if ($this->isThrowable()) {
            throw $this;
        }
    }

    /**
     * @return Exception[]
     */
    public function getExceptions()
    {
        return $this->exceptArray;
    }
}

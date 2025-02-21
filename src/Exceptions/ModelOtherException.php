<?php
namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelOtherException.
 */
class ModelOtherException extends Exception implements FieldException
{
    protected string $field_name = '';

    /**
     * ModelOtherException constructor.
     *
     * @param string         $field_name
     * @param string         $message
     * @param int            $code
     * @param Exception|null $previous
     */
    public function __construct(string $field_name, string $message = '', int $code = 0, ?Exception $previous = null)
    {
        $this->field_name = $field_name;

        if (empty($message)) {
            $message = \ucwords(\implode(' ', \explode('_', $field_name))).' is a required field';
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string
     */
    public function getFieldName(): string
    {
        return $this->field_name;
    }
}

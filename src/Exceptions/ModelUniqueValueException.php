<?php
namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelUniqueValueException.
 */
class ModelUniqueValueException extends Exception implements FieldException
{
    protected readonly string $field_name;
    protected readonly string $value;

    /**
     * ModelUniqueValueException constructor.
     */
    public function __construct(string $field_name, string $value, string $message = '', int $code = 0, ?Exception $previous = null)
    {
        $this->field_name = $field_name;
        $this->value      = $value;

        $finalMessage = $message;
        if (empty($finalMessage)) {
            $finalMessage  = \ucwords(\implode(' ', \explode('_', $this->field_name))).' is a unique field. ';
            $finalMessage .= 'A value of "'.$this->value.'" has already been used';
        }
        parent::__construct($finalMessage, $code, $previous);
    }

    public function getFieldName(): string
    {
        return $this->field_name;
    }
}

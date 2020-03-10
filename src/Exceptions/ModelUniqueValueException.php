<?php
namespace GCWorld\ORM\Exceptions;

use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelUniqueValueException.
 */
class ModelUniqueValueException extends \Exception implements FieldException
{
    protected $field_name = '';
    protected $value      = '';

    /**
     * ModelUniqueValueException constructor.
     *
     * @param string          $field_name
     * @param string          $value
     * @param string          $message
     * @param int             $code
     * @param \Exception|null $previous
     */
    public function __construct(string $field_name, string $value, string $message = '', int $code = 0, \Exception $previous = null)
    {
        $this->field_name = $field_name;
        $this->value      = $value;

        if (empty($message)) {
            $message  = \ucwords(\implode(' ', \explode('_', $field_name))).' is a unique field. ';
            $message .= 'A value of "'.$value.'" has already been used';
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

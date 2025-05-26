<?php
namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelImmutableException.
 */
class ModelImmutableException extends Exception implements FieldException
{
    protected readonly string $field_name;

    /**
     * ModelImmutableException constructor.
     */
    public function __construct(string $field_name, string $message = '', int $code = 0, ?Exception $previous = null)
    {
        $this->field_name = $field_name; // Initialize readonly property

        $finalMessage = $message;
        if (empty($finalMessage)) {
            $finalMessage = \ucwords(\implode(' ', \explode('_', $this->field_name))).' is an immutable field and cannot be changed';
        }
        parent::__construct($finalMessage, $code, $previous);
    }

    public function getFieldName(): string
    {
        return $this->field_name;
    }
}

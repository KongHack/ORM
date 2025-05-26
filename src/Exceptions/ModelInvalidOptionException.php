<?php
namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelInvalidOptionException.
 */
class ModelInvalidOptionException extends Exception implements FieldException
{
    protected readonly mixed $chosen;
    protected readonly array $possible;
    protected readonly string $field_name;

    /**
     * ModelInvalidOptionException constructor.
     */
    public function __construct(
        string $field_name,
        mixed $chosen,
        array $possible,
        string $message = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->field_name = $field_name;
        $this->chosen     = $chosen;
        $this->possible   = $possible;

        $finalMessage = $message;
        if (empty($finalMessage)) {
            $finalMessage = 'Invalid Option ('.$this->chosen.') Selected in Field ('.
                       \ucwords(\implode(' ', \explode('_', $this->field_name))).')';
        }
        parent::__construct($finalMessage, $code, $previous);
    }

    public function getChosen(): mixed
    {
        return $this->chosen;
    }

    public function getPossible(): array
    {
        return $this->possible;
    }

    public function getFieldName(): string
    {
        return $this->field_name;
    }
}

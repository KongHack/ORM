<?php
namespace GCWorld\ORM\Exceptions;

use Exception;
use GCWorld\ORM\Interfaces\FieldException;

/**
 * Class ModelInvalidOptionException.
 */
class ModelInvalidOptionException extends Exception implements FieldException
{
    protected $chosen;
    protected $possible;
    protected $field_name;

    /**
     * ModelInvalidOptionException constructor.
     *
     * @param string         $field_name
     * @param mixed          $chosen
     * @param array          $possible
     * @param string         $message
     * @param int            $code
     * @param Exception|null $previous
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

        if (empty($message)) {
            $message = 'Invalid Option ('.$this->chosen.') Selected in Field ('.
                       \ucwords(\implode(' ', \explode('_', $field_name))).')';
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * @return string|null
     */
    public function getChosen(): ?string
    {
        return $this->chosen;
    }

    /**
     * @return array|null
     */
    public function getPossible(): ?array
    {
        return $this->possible;
    }

    /**
     * @return string
     */
    public function getFieldName(): string
    {
        return $this->field_name;
    }
}

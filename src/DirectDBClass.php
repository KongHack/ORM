<?php
namespace GCWorld\ORM;

use GCWorld\ORM\Abstracts\DirectSingle;

/**
 * Class DirectDBClass
 * @package GCWorld\ORM
 */
abstract class DirectDBClass extends DirectSingle
{
    public function get(string $key): mixed
    {
        return parent::get($key);
    }

    /**
     * @param string[] $fields
     * @return array<string,mixed>
     */
    public function getArray(array $fields): array
    {
        return parent::getArray($fields);
    }

    /**
     * @return static
     */
    public function set(string $key, mixed $val): static
    {
        parent::set($key, $val);

        return $this;
    }

    /**
     * @param array<string,mixed> $data
     * @return static
     */
    public function setArray(array $data): static
    {
        parent::setArray($data);

        return $this;
    }
}

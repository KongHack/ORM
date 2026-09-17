<?php

namespace GCWorld\ORM;

use GCWorld\ORM\Abstracts\DirectSingle;

/**
 * Class DirectDBClass.
 */
abstract class DirectDBClass extends DirectSingle
{
    /**
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return parent::get($key);
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, mixed>
     */
    public function getArray(array $fields): array
    {
        return parent::getArray($fields);
    }

    /**
     * @param string $key
     * @param mixed  $val
     *
     * @return $this
     */
    public function set(string $key, mixed $val): static
    {
        parent::set($key, $val);

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return $this
     */
    public function setArray(array $data): static
    {
        parent::setArray($data);

        return $this;
    }
}

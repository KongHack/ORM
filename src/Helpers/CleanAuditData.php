<?php

namespace GCWorld\ORM\Helpers;

/**
 * CleanAuditData Class.
 */
class CleanAuditData
{
    /** @var array<array-key, mixed> */
    protected array $after  = [];

    /** @var array<array-key, mixed> */
    protected array $before = [];

    /**
     * @param array<array-key, mixed> $after
     *
     * @return void
     */
    public function setAfter(array $after): void
    {
        $this->after = $after;
    }

    /**
     * @param array<array-key, mixed> $before
     *
     * @return void
     */
    public function setBefore(array $before): void
    {
        $this->before = $before;
    }

    /**
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    public function addAfter(mixed $key, mixed $value): void
    {
        $this->after[$key] = $value;
    }

    /**
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    public function addBefore(mixed $key, mixed $value): void
    {
        $this->before[$key] = $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getAfter(): array
    {
        return $this->after;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getBefore(): array
    {
        return $this->before;
    }
}

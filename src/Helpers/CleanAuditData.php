<?php
namespace GCWorld\ORM\Helpers;

/**
 * CleanAuditData Class.
 */
class CleanAuditData
{
    protected array $after  = [];
    protected array $before = [];

    /**
     * @param array $after
     *
     * @return void
     */
    public function setAfter(array $after): void
    {
        $this->after = $after;
    }

    /**
     * @param array $before
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
     * @return array
     */
    public function getAfter(): array
    {
        return $this->after;
    }

    /**
     * @return array
     */
    public function getBefore(): array
    {
        return $this->before;
    }
}

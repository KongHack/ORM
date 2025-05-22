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
     * @param array<mixed,mixed> $after
     */
    public function setAfter(array $after): void
    {
        $this->after = $after;
    }

    /**
     * @param array<mixed,mixed> $before
     */
    public function setBefore(array $before): void
    {
        $this->before = $before;
    }

    public function addAfter(mixed $key, mixed $value): void
    {
        $this->after[$key] = $value;
    }

    public function addBefore(mixed $key, mixed $value): void
    {
        $this->before[$key] = $value;
    }

    /**
     * @return array<mixed,mixed>
     */
    public function getAfter(): array
    {
        return $this->after;
    }

    /**
     * @return array<mixed,mixed>
     */
    public function getBefore(): array
    {
        return $this->before;
    }
}

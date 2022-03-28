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
     * @return void
     */
    public function setAfter(array $after)
    {
        $this->after = $after;
    }

    /**
     * @param array $before
     * @return void
     */
    public function setBefore(array $before)
    {
        $this->before = $before;
    }

    /**
     * @param mixed $key
     * @param mixed $value
     * @return void
     */
    public function addAfter($key, $value)
    {
        $this->after[$key] = $value;
    }

    /**
     * @param mixed $key
     * @param mixed $value
     * @return void
     */
    public function addBefore($key, $value)
    {
        $this->before[$key] = $value;
    }

    /**
     * @return array
     */
    public function getAfter()
    {
        return $this->after;
    }

    /**
     * @return array
     */
    public function getBefore()
    {
        return $this->before;
    }
}

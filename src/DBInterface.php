<?php
namespace GCWorld\ORM;

interface DBInterface
{
    public function set($key, $val);
    public function get($key);
    public function save();
    public function getFieldKeys();
}

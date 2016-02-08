<?php
namespace GCWorld\ORM\Interfaces;

interface PublicDBInterface
{
    public function set($key, $val);
    public function get($key);
    public function save();
    public function getFieldKeys();
}

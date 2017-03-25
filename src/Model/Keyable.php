<?php
/**
 * Created by PhpStorm.
 * User: balint
 * Date: 2017. 03. 25.
 * Time: 15:14
 */

namespace RedisPageCache\Model;


interface Keyable
{
    public function get();
    public function getPattern();
}
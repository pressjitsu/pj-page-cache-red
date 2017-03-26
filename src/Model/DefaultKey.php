<?php
/**
 * Created by PhpStorm.
 * User: balint
 * Date: 2017. 03. 25.
 * Time: 15:20
 */

namespace RedisPageCache\Model;

class DefaultKey implements Keyable
{
    private $request;
    private $keyString = '';
    
    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->keyString = sprintf($this->getPattern(), $this->request->getHash());
    }

    /**
     * @return mixed
     */
    public function get()
    {
        return $this->keyString;
    }

    /**
     * @return mixed
     */
    public function getPattern()
    {
        return 'pjc-%s';
    }

}
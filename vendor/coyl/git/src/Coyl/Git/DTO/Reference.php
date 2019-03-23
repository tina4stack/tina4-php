<?php

namespace Coyl\Git\DTO;

class Reference
{
    protected $name;

    public function __construct($name)
    {
        $this->name = $name;
    }
}
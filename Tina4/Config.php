<?php
namespace Tina4;

class Config
{
    private $twigFilters = [];
    private $authMechanism = null;


    function addTwigFilter ($filterName, $function) {
        $this->twigFilters[$filterName] = $function;
    }

    function setAuth (\Tina4\Auth $auth) {
        $this->authMechanism = $auth;
    }

    function getTwigFilters() {
        return $this->twigFilters;
    }

    function getAuthentication() {
        if (!empty($this->authMechanism)) {
            return $this->authMechanism;
        } else {
            return false;
        }
    }
}
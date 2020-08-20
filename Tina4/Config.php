<?php
namespace Tina4;

class Config
{
    private $twigFilters = [];
    private $authMechanism;


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
        return $this->authMechanism;
    }
}
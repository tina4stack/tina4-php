<?php

namespace Tina4;

class Config
{
    private $twigFilters = [];
    private $authMechanism = null;


    /**
     * Adds a twig filter for use in your templates
     * @param $filterName string Name of the filter
     * @param $function null Anonymous function which takes parameters based on the name of the twig filter
     */
    function addTwigFilter($filterName, $function)
    {
        $this->twigFilters[$filterName] = $function;
    }

    /**
     * Sets an auth parameter
     * @param $auth Auth
     */
    function setAuth(Auth $auth)
    {
        $this->authMechanism = $auth;
    }

    /**
     * Sets an auth parameter - alias of setAuth
     * @param $auth Auth
     */
    function setAuthentication(Auth $auth)
    {
        $this->authMechanism = $auth;
    }

    /**
     * Gets all the twig filters
     * @return array
     */
    function getTwigFilters()
    {
        return $this->twigFilters;
    }

    /**
     * Gets the auth variable
     * @return false|null
     */
    function getAuthentication()
    {
        if (!empty($this->authMechanism)) {
            return $this->authMechanism;
        } else {
            return false;
        }
    }
}
<?php

namespace Tina4;

class Config
{
    protected $twigFilters = [];
    protected $twigFunctions = [];
    protected $twigGlobals = [];
    protected $authMechanism = null;

    /**
     * Adds a twig filter for use in your templates
     * @param $filterName string Name of the filter
     * @param $function null Anonymous function which takes parameters based on the name of the twig filter
     */
    public function addTwigFilter($filterName, $function)
    {
        $this->twigFilters[$filterName] = $function;
    }

    /**
     * Adds a twig function for use in your templates
     * @param $functionName string Name of the filter
     * @param $function null Anonymous function which takes parameters based on the name of the twig filter
     */
    public function addTwigFunction($functionName, $function)
    {
        $this->twigFunctions[$functionName] = $function;
    }

    /**
     * Adds a twig global function
     * @param $globalName
     * @param $function
     */
    public function addTwigGlobal($globalName, $function)
    {
        $this->twigGlobals[$globalName] = $function;
    }


    /**
     * Sets an auth parameter
     * @param $auth Auth
     */
    public function setAuth(Auth $auth)
    {
        $this->authMechanism = $auth;
    }

    /**
     * Sets an auth parameter - alias of setAuth
     * @param $auth Auth
     */
    public function setAuthentication(Auth $auth)
    {
        $this->authMechanism = $auth;
    }

    /**
     * Gets all the twig filters
     * @return array
     */
    public function getTwigFilters(): array
    {
        return $this->twigFilters;
    }

    /**
     * Gets all the twig globals
     * @return array
     */
    public function getTwigGlobals(): array
    {
        return $this->twigGlobals;
    }

    /**
     * Gets all the twig functions
     * @return array
     */
    public function getTwigFunctions(): array
    {
        return $this->twigFunctions;
    }

    /**
     * Gets the auth variable
     * @return false|null
     */
    public function getAuthentication()
    {
        if (!empty($this->authMechanism)) {
            return $this->authMechanism;
        }

        return false;
    }
}
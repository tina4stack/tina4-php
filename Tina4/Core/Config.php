<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Config
 * A container for settings passed to the Tina4 library, used currently for custom Auth, Twig filters, functions & globals
 * @package Tina4
 */
class Config
{
    protected array $twigFilters = [];
    protected array $twigFunctions = [];
    protected array $twigGlobals = [];
    protected ?Auth $authMechanism = null;

    protected $initFunction;

    public function callInitFunction(): void
    {
        call_user_func_array($this->initFunction, [$this]);
    }

    public function __construct($initFunction=null)
    {
        if ($initFunction !== null)
        {
            $this->initFunction = $initFunction;
        }
    }

    /**
     * Adds a twig filter for use in your templates
     * @param $filterName string Name of the filter
     * @param $function null Anonymous function which takes parameters based on the name of the twig filter
     */
    public function addTwigFilter(string $filterName, $function): void
    {
        $this->twigFilters[$filterName] = $function;
    }

    /**
     * Adds a twig function for use in your templates
     * @param $functionName string Name of the filter
     * @param $function null Anonymous function which takes parameters based on the name of the twig filter
     */
    public function addTwigFunction(string $functionName, $function): void
    {
        $this->twigFunctions[$functionName] = $function;
    }

    /**
     * Adds a twig global function
     * @param $globalName
     * @param $function
     */
    public function addTwigGlobal(string $globalName, $function): void
    {
        $this->twigGlobals[$globalName] = $function;
    }


    /**
     * Sets an auth parameter
     * @param $auth Auth
     */
    public function setAuth(Auth $auth): void
    {
        $this->authMechanism = $auth;
    }

    /**
     * Sets an auth parameter - alias of setAuth
     * @param $auth Auth
     */
    public function setAuthentication(?Auth $auth): void
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
     * @return Auth
     */
    public function getAuthentication(): ?Auth
    {
        if (!empty($this->authMechanism)) {
            return $this->authMechanism;
        }
        return null;
    }


}
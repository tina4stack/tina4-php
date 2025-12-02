<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * The service runner
 * @package Tina4
 */
class Service extends Data
{
    private $sleepTime = 5;
    /***
     * Name of the file to store the serialized service in
     * @var false|string
     */
    private string|false $serviceSaveFilename;
    private string $servicesLastTimeFilename;

    /**
     * Service constructor
     */
    function __construct()
    {
        parent::__construct();
        $this->serviceSaveFilename = TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "services.data";
        $this->servicesLastTimeFilename = TINA4_DOCUMENT_ROOT . "bin" . DIRECTORY_SEPARATOR . "servicesLastTime.data";
    }

    /**
     * Add process to the list to be run
     * @param Process $process
     */
    public function addProcess(\Tina4\Process $process): void
    {
        if (file_exists($this->serviceSaveFilename)) {
            $services = unserialize(file_get_contents($this->serviceSaveFilename));
        } else {
            $services = [];
        }
        $services[$process->name] = $process;
        file_put_contents($this->serviceSaveFilename, serialize($services));
    }

    /**
     * Add process to the list to be run
     * @param string $name
     */
    public function removeProcess(string $name): void
    {
        if (file_exists($this->serviceSaveFilename)) {
            $services = unserialize(file_get_contents($this->serviceSaveFilename));
        } else {
            $services = [];
        }

        if (isset($services[$name])) {
            unset($services[$name]);
            file_put_contents($this->serviceSaveFilename, serialize($services));
        }
    }

    /**
     * Get a list of processes
     * @return array|mixed
     */
    public function getProcesses()
    {
        if (file_exists($this->serviceSaveFilename)) {
            $services = unserialize(file_get_contents($this->serviceSaveFilename));
        } else {
            $services = [];
        }
        return $services;
    }

    /**
     * Add process to the list of last run processes with their last time
     * @param Process $process
     */
    public function addLastTimeProcess(\Tina4\Process $process): void
    {
        if (file_exists($this->servicesLastTimeFilename)) {
            $services = unserialize(file_get_contents($this->servicesLastTimeFilename));
        } else {
            $services = [];
        }
        $services[$process->name] = $process;
        file_put_contents($this->servicesLastTimeFilename, serialize($services));
    }

    /**
     * Get the last run processes with their last run time
     * @return array|mixed
     */
    public function getLastTimeProcesses()
    {
        if (file_exists($this->servicesLastTimeFilename)) {
            $services = unserialize(file_get_contents($this->servicesLastTimeFilename));
        } else {
            $services = [];
        }

        return $services;
    }

    /**
     * Sleep time between each run
     * @return int
     */
    public function getSleepTime(): int
    {
        // Override sleep time default with env set time
        if(isset($_ENV["TINA4_SERVICE_TIME"])){
            $this->sleepTime = $_ENV["TINA4_SERVICE_TIME"];
        }
        \Tina4\Debug::message("Process Sleep Time: {$this->sleepTime}", TINA4_LOG_DEBUG);

        return $this->sleepTime;
    }

}

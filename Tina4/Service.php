<?php
namespace Tina4;

class Service extends Data
{
    private $sleepTime = 5;
    private $servicePath;

    /**
     * Service constructor
     */
    function __construct()
    {
        parent::__construct();
        $this->servicePath = $this->rootFolder.DIRECTORY_SEPARATOR."cache".DIRECTORY_SEPARATOR."services";
    }

    /**
     * Add process to the list to be run
     * @param Process $process
     */
    public function addProcess(\Tina4\Process $process) {
        if (file_exists($this->servicePath)) {
            $services = unserialize(file_get_contents($this->servicePath));
        } else {
            $services = [];
        }
        $services[$process->name] = $process;
        file_put_contents($this->servicePath, serialize($services));
    }

    /**
     * Add process to the list to be run
     * @param Process $process
     */
    public function removeProcess($name) {
        if (file_exists($this->servicePath)) {
            $services = unserialize(file_get_contents($this->servicePath));
        } else {
            $services = [];
        }

        if (isset($services[$name])) {
            unset($services[$name]);
            file_put_contents($this->servicePath, serialize($services));
        }
    }

    /**
     * Get a list of processes
     * @return array|mixed
     */
    public function getProcesses () {
        if (file_exists($this->servicePath)) {
            $services = unserialize(file_get_contents($this->servicePath));
        } else {
            $services = [];
        }
        return $services;
    }

    /**
     * Sleep time between each run
     * @return int
     */
    public function getSleepTime() {
        return $this->sleepTime;
    }
}
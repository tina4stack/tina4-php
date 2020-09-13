<?php
namespace Tina4;

class Service extends Data
{
    private $sleepTime = 5;
    private $servicePath;

    function __construct($DBA = null)
    {
        parent::__construct();
        $this->servicePath = $this->rootFolder.DIRECTORY_SEPARATOR."cache".DIRECTORY_SEPARATOR."services";
    }

    public function addProcess(\Tina4\Process $process) {
        if (file_exists($this->servicePath)) {
            $services = unserialize(file_get_contents($this->servicePath));
        } else {
            $services = [];
        }
        $services[$process->name] = $process;
        file_put_contents($this->servicePath, serialize($services));
    }

    public function getProcesses () {
        if (file_exists($this->servicePath)) {
            $services = unserialize(file_get_contents($this->servicePath));
        } else {
            $services = [];
        }

        return $services;
    }

    public function getSleepTime() {
        return $this->sleepTime;
    }
}
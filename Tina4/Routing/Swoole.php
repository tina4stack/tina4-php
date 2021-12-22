<?php

namespace Tina4;
/**
 * An encapsulation for the Openswoole services
 */
class Swoole
{

    private $server;

    /**
     * @param int $port
     * @param array $events array ["on" => $method]
     * @param bool $daemonize
     * @param int $workers
     */
    public function __construct(int $port, array $events, bool $daemonize=false, int $workers=10)
    {
        $this->server = new \Swoole\Server("0.0.0.0", $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
        $this->server->set([
            'worker_num' => $workers,   // The number of worker processes
            'daemonize' => $daemonize, // Whether to start as a daemon process
            'backlog' => $workers*10,    // TCP backlog connection number
        ]);
        foreach ($events as $event => $callback) {
            $this->server->on($event, $callback);
        }
    }

    /**
     * Add Listener service
     * @param int $port
     * @param array $events
     */
    public function addListener(int $port, array $events) {
        if (!empty($this->server)) {
            $listener = $this->server->listen("0.0.0.0", $port, SWOOLE_TCP);
            foreach ($events as $event => $callback) {
                $listener->on($event, $callback);
            }
        }
    }

    /**
     * Add swoole table name
     * @param $tableName
     * @param $table
     */
    public function addTable($tableName, $table) {
        if (!empty($this->server)) {
            $this->server->{$tableName} = $table;
        }
    }

    /**
     * Starts the swoole server
     */
    public function start() {
        if (!empty($this->server)) {
            $this->server->start();
        }
    }

}
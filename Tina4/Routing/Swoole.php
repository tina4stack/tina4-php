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
     * @param int $workers
     */
    public function __construct(int $port, array $events, $daemonize=false, int $workers=10)
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

    public function addListener(int $port, array $events) {
        $listener = $this->server->listen("0.0.0.0", $port, SWOOLE_TCP);
        foreach ($events as $event => $callback) {
            $listener->on($event, $callback);
        }
    }

    /**
     * Starts the swoole server
     */
    public function start() {
        $this->server->start();
    }

}
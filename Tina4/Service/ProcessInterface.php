<?php


namespace Tina4;


interface ProcessInterface
{
    /**
     * Process constructor.
     * @param string $name
     */
    public function __construct(string $name = "");

    /**
     * Can run
     * @return bool
     */
    public function canRun(): bool;

    /**
     * The script that runs things
     */
    public function run();

}
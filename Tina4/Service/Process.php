<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Extend this class to make your own processes
 * @package Tina4
 */
class Process implements ProcessInterface
{
    public $name;
    public $timing;
    public $lastTime;

    /**
     * Process constructor.
     * @param string $name
     * @param string $timing
     * @param string $lastTime
     */
    public function __construct(string $name = "", string $timing = "", string $lastTime="")
    {
        if (!empty($name)) {
            $this->name = $name;
            $this->timing = $timing;
            $this->lastTime = $lastTime;
        }
        // No validation is done on the incoming $timing. Poorly constructed timings will result in the process not running
        Debug::message("Initializing Process: {$this->name}" . " with timing " . $this->timing . " last run " . $this->lastTime, TINA4_LOG_DEBUG);
    }
    /**
     * Function to add timing interval functionality to the service runner.
     * Is backward compatible and if not used, will continue standard runs every TINA4_SERVICE_TIME
     * Compares the given process timing parameters (based on linux crons [* * * * *]) against the current time to determine if it needs to run.
     * Uses a file named timer_ProcessName.dat stored in the bin folder to ensure it only runs once per timing window.
     * This is necessary due to the services sleep timer.
     * CAUTION: For this to work adequately the services sleep timer should probably be set to less than 50 seconds to avoid missing timed runs
     * CAUTION: This should never be used for accurate timing as it could be upto TINA4_SERVICE_TIME seconds delayed
     *  Accepts wild card '*' - runs on every timing
     *  Accepts interval '*\/5' - runs every 5 units of timing. Note backslash not included is an escape here
     *  Accepts list '1,4,34,57' - runs every given unit of timing
     *  Accepts value '3' - runs on the given unit of timing
     * @return bool
     */
    function timeToRun(): bool
    {
        // if no process timing has been used then return true every time
        // This defaults to the standard services timing set by TINA4_SERVICE_TIME
        $processTime = $this->timing;
        if (empty($processTime)){

            return true;
        }

        // set the current timing
        $dt = new \DateTime();
        $currentTiming[0] = (int) $dt->format('i');   // minute
        $currentTiming[1] = (int) $dt->format('G');     // hour
        $currentTiming[2] = (int) $dt->format('j');      // day
        $currentTiming[3] = (int) $dt->format('n');    // month
        $currentTiming[4] = (int) $dt->format('w');  // weekday


        // Check if the current time fits in the processing time window
        // As the service can technically run every second. There might be a number of times that fit in the processing time window.
        $processTiming = explode(" ", $processTime);
        $processEqualsCurrent = true;
        for ($i = 0; $i < 5; $i++) {
            if (!$this->fieldMatches($processTiming[$i], $currentTiming[$i])) {
                $processEqualsCurrent = false;
            }
        }

        if ($processEqualsCurrent) {
            // If we are here, it means we are ready to run. The Current time is a Process Time
            // We need to ensure that the Process Time does not match the last Process Time, ie it has just run.
            $lastTimeProcesses = (new Service())->getLastTimeProcesses();
            $lastTiming = explode(" ", $lastTimeProcesses[$this->name]->lastTime);
            if (empty($lastTimeProcesses[$this->name]->lastTime)) {
                // No last timing set so the process should run
                $processEqualsLast = false;
            } else {
                $processEqualsLast = true;
                for ($i = 0; $i < 5; $i++) {
                    if (!$this->fieldMatches($processTiming[$i], $lastTiming[$i])) {
                        $processEqualsLast = false;
                    }
                }
            }
            if ($processEqualsLast) {
                // The process has already run once in this process window

                return false;
            } else {
                // The process has not run in this process window and should run
                $this->setLastTime($currentTiming);

                return true;
            }
        } else {
            // The process is not timed to run. Update the last time
            $this->setLastTime($currentTiming);

            return false;
        }
    }

    /**
     * Function to set a time stamp. Used to ensure a service does not run more than once in a time window.
     * @param array $currentTiming
     * @param \Tina4\Process $process
     * @return void
     */
    private function setLastTime(array $currentTiming): void
    {
        $lastTime = implode(" ", $currentTiming);
        // save the timing file
        //file_put_contents("bin" . DIRECTORY_SEPARATOR . "timing_" . $this->name . ".dat", $lastTime);
        $this->lastTime = $lastTime;
        (new Service())->addLastTimeProcess($this);
    }

    /**
     * Function compares two timings against each other checking if the declared processTime is suitable at the given time
     * @param string $processField
     * @param int $timingField
     * @return bool
     */
    private function fieldMatches(string $processField, int $timingField): bool
    {
        if ($processField === '*') {
            return true;
        }

        // Interval match (e.g., */5)
        if (preg_match('/^\*\/(\d+)$/', $processField, $matches)) {
            $step = (int)$matches[1];
            return $timingField % $step === 0;
        }

        // List match (e.g., 1,2,3)
        if (strpos($processField, ',') !== false) {
            $values = explode(',', $processField);
            return in_array((string)$timingField, $values, true);
        }

        // Exact value
        return (int)$processField === $timingField;
    }

    /**
     * Returns true or false whether the process can run
     * @return bool
     */
    public function canRun(): bool
    {
        // TODO: Implement canRun() method.
    }

    /**
     * The code that will run when this process can run
     */
    public function run()
    {
        // TODO: Implement run() method.
    }
}

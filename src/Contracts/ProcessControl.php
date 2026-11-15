<?php

namespace Laravel\Horizon\Contracts;

interface ProcessControl
{
    /**
     * Register the given signal handlers for the current process.
     *
     * @param  array<int, \Closure>  $handlers
     * @return void
     */
    public function listen(array $handlers);

    /**
     * Send the given signal to the process with the given ID.
     *
     * @param  int  $processId
     * @param  int  $signal
     * @return bool
     */
    public function signal($processId, $signal);

    /**
     * Send the given signal to the given process instance.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @param  int  $signal
     * @return void
     *
     * @throws \Symfony\Component\Process\Exception\ExceptionInterface
     */
    public function signalProcess($process, $signal);

    /**
     * Get the error message for the last failed signal.
     *
     * @return string
     */
    public function lastError();

    /**
     * Determine if the parent of the current process is still running.
     *
     * @return bool
     */
    public function parentIsRunning();

    /**
     * Get the IDs of the processes whose command line matches the given pattern.
     *
     * @param  string  $pattern
     * @return array
     */
    public function find($pattern);

    /**
     * Get the IDs of the child processes of the given process.
     *
     * @param  int  $processId
     * @return array
     */
    public function children($processId);

    /**
     * Get the process listing lines that contain the given search term.
     *
     * @param  string  $search
     * @return string
     */
    public function listing($search);

    /**
     * Prepare the given command line for launching a long-running child process.
     *
     * @param  string  $command
     * @return string
     */
    public function prepareCommand($command);
}

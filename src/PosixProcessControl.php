<?php

namespace Laravel\Horizon;

use Laravel\Horizon\Contracts\ProcessControl;
use Symfony\Component\Process\Process;

class PosixProcessControl implements ProcessControl
{
    /**
     * The command executor.
     *
     * @var \Laravel\Horizon\Exec
     */
    public $exec;

    /**
     * Create a new POSIX process control instance.
     *
     * @param  \Laravel\Horizon\Exec  $exec
     * @return void
     */
    public function __construct(Exec $exec)
    {
        $this->exec = $exec;
    }

    /**
     * Register the given signal handlers for the current process.
     *
     * @param  array<int, \Closure>  $handlers
     * @return void
     */
    public function listen(array $handlers)
    {
        pcntl_async_signals(true);

        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    /**
     * Run the handlers for any signals received since the last dispatch.
     *
     * @return void
     */
    public function dispatch()
    {
        //
    }

    /**
     * Send the given signal to the process with the given ID.
     *
     * @param  int  $processId
     * @param  int  $signal
     * @return bool
     */
    public function signal($processId, $signal)
    {
        return posix_kill($processId, $signal);
    }

    /**
     * Send the given signal to the given process instance.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @param  int  $signal
     * @return void
     *
     * @throws \Symfony\Component\Process\Exception\ExceptionInterface
     */
    public function signalProcess($process, $signal)
    {
        $process->signal($signal);
    }

    /**
     * Get the error message for the last failed signal.
     *
     * @return string
     */
    public function lastError()
    {
        return posix_strerror(posix_get_last_error());
    }

    /**
     * Determine if the parent of the current process is still running.
     *
     * @param  int  $parentId
     * @return bool
     */
    public function parentIsRunning($parentId)
    {
        return posix_getppid() > 1;
    }

    /**
     * Get the IDs of the processes whose command line matches the given pattern.
     *
     * @param  string  $pattern
     * @return array
     */
    public function find($pattern)
    {
        return $this->exec->run("pgrep -f {$pattern}");
    }

    /**
     * Get the IDs of the child processes of the given process.
     *
     * @param  int  $processId
     * @return array
     */
    public function children($processId)
    {
        return $this->exec->run("pgrep -P {$processId}");
    }

    /**
     * Get the process listing lines that contain the given search term.
     *
     * @param  string  $search
     * @return string
     */
    public function listing($search)
    {
        $process = Process::fromShellCommandline('exec ps aux | grep '.$search, null, ['COLUMNS' => '2000']);

        $process->run();

        return $process->getOutput();
    }

    /**
     * Prepare the given command line for launching a long-running child process.
     *
     * @param  string  $command
     * @return string
     */
    public function prepareCommand($command)
    {
        return $command;
    }

    /**
     * Inspect the given child process during a supervisor loop.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @return void
     */
    public function monitorProcess($process)
    {
        //
    }

    /**
     * Get the process instance to start in place of the given stopped process.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @return \Symfony\Component\Process\Process
     */
    public function restartProcess($process)
    {
        return $process;
    }

    /**
     * Prepare the given queue worker to respond to process signals.
     *
     * @param  \Illuminate\Queue\Worker  $worker
     * @param  int  $timeout
     * @return void
     */
    public function watchWorker($worker, $timeout)
    {
        //
    }
}

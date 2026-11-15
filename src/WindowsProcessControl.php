<?php

namespace Laravel\Horizon;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Laravel\Horizon\Contracts\ProcessControl;

class WindowsProcessControl implements ProcessControl
{
    /**
     * The environment variable that identifies a Horizon child process.
     *
     * Symfony starts every child process on Windows through "cmd.exe", so the
     * process ID a parent sees is the shell's rather than PHP's. Each child
     * is therefore given an ID of its own that both sides can agree on.
     */
    const PROCESS_ID = 'HORIZON_PROCESS_ID';

    /**
     * The number of seconds between checks that a parent process is running.
     *
     * @var int
     */
    public $parentCheckInterval = 5;

    /**
     * The signal mailbox instance.
     *
     * @var \Laravel\Horizon\SignalMailbox
     */
    public $mailbox;

    /**
     * The process table instance.
     *
     * @var \Laravel\Horizon\WindowsProcessTable
     */
    public $processes;

    /**
     * The Redis connection instance.
     *
     * @var \Illuminate\Contracts\Redis\Factory
     */
    public $redis;

    /**
     * The registered signal handlers, keyed by signal.
     *
     * @var array<int, \Closure>
     */
    protected $handlers = [];

    /**
     * Indicates if the process is listening for signals.
     *
     * @var bool
     */
    protected $listening = false;

    /**
     * The error message for the last failed signal.
     *
     * @var string
     */
    protected $lastError = '';

    /**
     * The time the parent process was last checked.
     *
     * @var int|null
     */
    protected $parentCheckedAt;

    /**
     * The result of the last parent process check.
     *
     * @var bool
     */
    protected $parentWasRunning = true;

    /**
     * Create a new Windows process control instance.
     *
     * @param  \Laravel\Horizon\SignalMailbox  $mailbox
     * @param  \Laravel\Horizon\WindowsProcessTable  $processes
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @return void
     */
    public function __construct(SignalMailbox $mailbox, WindowsProcessTable $processes, RedisFactory $redis)
    {
        $this->mailbox = $mailbox;
        $this->processes = $processes;
        $this->redis = $redis;
    }

    /**
     * Register the given signal handlers for the current process.
     *
     * @param  array<int, \Closure>  $handlers
     * @return void
     */
    public function listen(array $handlers)
    {
        foreach ($handlers as $signal => $handler) {
            $this->handlers[$signal] = $handler;
        }

        if ($this->listening) {
            return;
        }

        $this->listening = true;

        // Windows reuses process IDs quickly, so anything left at our process ID
        // was meant for an earlier process. Signals sent to our Horizon process
        // ID were meant for us, since our parent may signal us before we boot.
        $this->mailbox->reset('pid:'.getmypid());

        $this->listenForConsoleInterrupts();
    }

    /**
     * Run the handlers for any signals received since the last dispatch.
     *
     * @return void
     */
    public function dispatch()
    {
        if (! $this->listening) {
            return;
        }

        foreach ($this->addresses() as $address) {
            foreach ($this->mailbox->drain($address) as $signal) {
                if (isset($this->handlers[$signal])) {
                    ($this->handlers[$signal])($signal);
                }
            }
        }
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
        if (! $this->processes->isRunning($processId)) {
            $this->lastError = 'No such process';

            return false;
        }

        if ($signal === SIGKILL) {
            return $this->processes->kill($processId) ?: tap(false, function () {
                $this->lastError = 'Unable to kill the process';
            });
        }

        $this->mailbox->send('pid:'.$processId, $signal);

        return true;
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
        $id = $this->idFor($process);

        if ($id !== null && $signal !== SIGKILL) {
            $this->mailbox->send('id:'.$id, $signal);

            return;
        }

        // A process that was not started by Horizon has no mailbox, so the only
        // signals we are able to deliver to it are the ones that stop it.
        if (in_array($signal, [SIGTERM, SIGKILL, SIGINT, SIGQUIT], true)) {
            $process->stop(0);
        }
    }

    /**
     * Get the error message for the last failed signal.
     *
     * @return string
     */
    public function lastError()
    {
        return $this->lastError;
    }

    /**
     * Determine if the parent of the current process is still running.
     *
     * @param  int  $parentId
     * @return bool
     */
    public function parentIsRunning($parentId)
    {
        $now = CarbonImmutable::now()->getTimestamp();

        if ($this->parentCheckedAt === null || $now - $this->parentCheckedAt >= $this->parentCheckInterval) {
            $this->parentCheckedAt = $now;

            $this->parentWasRunning = $this->processes->isRunning($parentId);
        }

        return $this->parentWasRunning;
    }

    /**
     * Get the IDs of the processes whose command line matches the given pattern.
     *
     * @param  string  $pattern
     * @return array
     */
    public function find($pattern)
    {
        $pattern = '#'.str_replace('#', '\#', $pattern).'#';

        return collect($this->processes->php())
            ->filter(fn ($process) => preg_match($pattern, $process['command']) === 1)
            ->pluck('pid')
            ->values()
            ->all();
    }

    /**
     * Get the IDs of the child processes of the given process.
     *
     * @param  int  $processId
     * @return array
     */
    public function children($processId)
    {
        $processes = collect($this->processes->all())->keyBy('pid');

        // Children are started through a "cmd.exe" shell, so a PHP process whose
        // parent is a shell started by the given process is also its child.
        return $processes
            ->filter(function ($process) use ($processes, $processId) {
                if ($process['name'] === 'cmd.exe') {
                    return false;
                }

                $parent = $processes->get($process['parent']);

                return $process['parent'] === (int) $processId ||
                    ($parent && $parent['name'] === 'cmd.exe' && $parent['parent'] === (int) $processId);
            })
            ->pluck('pid')
            ->values()
            ->all();
    }

    /**
     * Get the process listing lines that contain the given search term.
     *
     * @param  string  $search
     * @return string
     */
    public function listing($search)
    {
        return collect($this->processes->php())
            ->pluck('command')
            ->filter(fn ($command) => str_contains($command, $search))
            ->implode("\n");
    }

    /**
     * Prepare the given command line for launching a long-running child process.
     *
     * @param  string  $command
     * @return string
     */
    public function prepareCommand($command)
    {
        $command = preg_replace('/^exec\s+/', '', $command);

        return sprintf('set "%s=%s" && %s', static::PROCESS_ID, bin2hex(random_bytes(8)), $command);
    }

    /**
     * Inspect the given child process during a supervisor loop.
     *
     * Without the pcntl extension a worker cannot interrupt a job that runs past
     * its timeout, so its supervisor stops the worker's process tree instead.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @return void
     */
    public function monitorProcess($process)
    {
        if (! $process->isRunning() || ($id = $this->idFor($process)) === null) {
            return;
        }

        $deadline = $this->connection()->get($this->deadlineKey($id));

        if ($deadline && CarbonImmutable::now()->getTimestamp() > (int) $deadline) {
            $this->connection()->del($this->deadlineKey($id));

            $process->stop(0);
        }
    }

    /**
     * Prepare the given queue worker to respond to process signals.
     *
     * Signals are read from the mailbox each time the worker loops. A paused
     * worker stops firing its "Looping" event, so pausing is done by having
     * that event's listener tell the worker not to run instead.
     *
     * @param  \Illuminate\Queue\Worker  $worker
     * @param  int  $timeout
     * @return void
     */
    public function watchWorker($worker, $timeout)
    {
        $paused = $worker->paused;

        $worker->paused = false;

        $this->listen([
            SIGTERM => fn () => $worker->shouldQuit = true,
            SIGQUIT => fn () => $worker->shouldQuit = true,
            SIGINT => fn () => $worker->shouldQuit = true,
            SIGUSR2 => function () use (&$paused) {
                $paused = true;
            },
            SIGCONT => function () use (&$paused) {
                $paused = false;
            },
        ]);

        $events = app(Dispatcher::class);

        $events->listen(Looping::class, function () use (&$paused) {
            $this->forgetDeadline();

            $this->dispatch();

            return $paused ? false : null;
        });

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($timeout) {
            $this->recordDeadline($event->job->timeout() ?? $timeout);
        });
    }

    /**
     * Record the time by which the current worker's job must finish.
     *
     * @param  int|null  $timeout
     * @return void
     */
    protected function recordDeadline($timeout)
    {
        if (! $timeout || ($id = getenv(static::PROCESS_ID)) === false) {
            return;
        }

        $this->connection()->setex($this->deadlineKey($id), (int) $timeout + 60, CarbonImmutable::now()->getTimestamp() + (int) $timeout);
    }

    /**
     * Forget the current worker's job deadline.
     *
     * @return void
     */
    protected function forgetDeadline()
    {
        if (($id = getenv(static::PROCESS_ID)) !== false) {
            $this->connection()->del($this->deadlineKey($id));
        }
    }

    /**
     * Listen for Ctrl+C and Ctrl+Break in the console.
     *
     * Windows sends console interrupts to every process attached to a console,
     * so each Horizon process handles them as a request to stop gracefully.
     *
     * @return void
     */
    protected function listenForConsoleInterrupts()
    {
        if (! function_exists('sapi_windows_set_ctrl_handler')) {
            return;
        }

        @sapi_windows_set_ctrl_handler(function () {
            $handler = $this->handlers[SIGINT] ?? $this->handlers[SIGTERM] ?? null;

            if ($handler) {
                $handler(SIGINT);
            }
        });
    }

    /**
     * Get the mailbox addresses for the current process.
     *
     * @return array<int, string>
     */
    protected function addresses()
    {
        $addresses = ['pid:'.getmypid()];

        if (($id = getenv(static::PROCESS_ID)) !== false && $id !== '') {
            $addresses[] = 'id:'.$id;
        }

        return $addresses;
    }

    /**
     * Get the Horizon process ID assigned to the given process.
     *
     * @param  \Symfony\Component\Process\Process  $process
     * @return string|null
     */
    protected function idFor($process)
    {
        return preg_match('/'.static::PROCESS_ID.'=([0-9a-f]+)/', $process->getCommandLine(), $matches)
            ? $matches[1]
            : null;
    }

    /**
     * Get the Redis key for the given process's job deadline.
     *
     * @param  string  $id
     * @return string
     */
    protected function deadlineKey($id)
    {
        return 'job-deadline:'.$id;
    }

    /**
     * Get the Redis connection instance.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function connection()
    {
        return $this->redis->connection('horizon');
    }
}

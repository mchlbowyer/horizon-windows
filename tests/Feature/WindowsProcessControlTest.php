<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Laravel\Horizon\SignalMailbox;
use Laravel\Horizon\Tests\IntegrationTest;
use Laravel\Horizon\WindowsProcessControl;
use Laravel\Horizon\WindowsProcessTable;
use Mockery;
use Symfony\Component\Process\Process;

class WindowsProcessControlTest extends IntegrationTest
{
    protected function tearDown(): void
    {
        putenv(WindowsProcessControl::PROCESS_ID);

        parent::tearDown();
    }

    public function test_signals_sent_to_a_process_id_are_dispatched_to_its_handlers()
    {
        $processes = $this->processes();
        $processes->shouldReceive('isRunning')->with(getmypid())->andReturn(true);

        $control = $this->control($processes);
        $received = [];

        $control->listen([
            SIGUSR2 => function () use (&$received) {
                $received[] = 'pause';
            },
            SIGCONT => function () use (&$received) {
                $received[] = 'continue';
            },
        ]);

        $this->assertTrue($control->signal(getmypid(), SIGUSR2));
        $this->assertTrue($control->signal(getmypid(), SIGCONT));
        $this->assertSame([], $received);

        $control->dispatch();

        $this->assertSame(['pause', 'continue'], $received);
    }

    public function test_signalling_a_missing_process_fails()
    {
        $processes = $this->processes();
        $processes->shouldReceive('isRunning')->with(999999)->andReturn(false);

        $control = $this->control($processes);

        $this->assertFalse($control->signal(999999, SIGTERM));
        $this->assertSame('No such process', $control->lastError());
    }

    public function test_kill_signals_stop_the_process_tree()
    {
        $processes = $this->processes();
        $processes->shouldReceive('isRunning')->with(123)->andReturn(true);
        $processes->shouldReceive('kill')->once()->with(123)->andReturn(true);

        $this->assertTrue($this->control($processes)->signal(123, SIGKILL));
    }

    public function test_listening_discards_signals_meant_for_an_earlier_process()
    {
        $processes = $this->processes();
        $processes->shouldReceive('isRunning')->andReturn(true);

        $control = $this->control($processes);
        $control->signal(getmypid(), SIGTERM);

        $received = false;
        $control->listen([SIGTERM => function () use (&$received) {
            $received = true;
        }]);
        $control->dispatch();

        $this->assertFalse($received);
    }

    public function test_commands_are_given_a_horizon_process_id_without_exec()
    {
        $command = $this->control()->prepareCommand('exec @php artisan horizon:work');

        $this->assertMatchesRegularExpression(
            '/^set "HORIZON_PROCESS_ID=[0-9a-f]{16}" && @php artisan horizon:work$/', $command
        );
    }

    public function test_signals_sent_to_a_child_process_reach_its_horizon_process_id()
    {
        $control = $this->control();
        $process = Process::fromShellCommandline($control->prepareCommand('exec @php artisan horizon:work'));

        preg_match('/HORIZON_PROCESS_ID=([0-9a-f]+)/', $process->getCommandLine(), $matches);
        putenv(WindowsProcessControl::PROCESS_ID.'='.$matches[1]);

        $child = $this->control();
        $received = false;
        $child->listen([SIGTERM => function () use (&$received) {
            $received = true;
        }]);

        $control->signalProcess($process, SIGTERM);
        $child->dispatch();

        $this->assertTrue($received);
    }

    public function test_child_processes_include_php_processes_started_through_a_shell()
    {
        $processes = $this->processes();
        $processes->shouldReceive('all')->andReturn([
            ['pid' => 10, 'parent' => 1, 'name' => 'php.exe', 'command' => 'php artisan horizon:supervisor'],
            ['pid' => 11, 'parent' => 10, 'name' => 'cmd.exe', 'command' => 'cmd /C (php artisan horizon:work)'],
            ['pid' => 12, 'parent' => 11, 'name' => 'php.exe', 'command' => 'php artisan horizon:work'],
            ['pid' => 13, 'parent' => 10, 'name' => 'php.exe', 'command' => 'php artisan horizon:work'],
            ['pid' => 14, 'parent' => 99, 'name' => 'php.exe', 'command' => 'php artisan horizon:work'],
        ]);

        $this->assertSame([12, 13], $this->control($processes)->children(10));
    }

    public function test_processes_can_be_found_and_listed_by_command_line()
    {
        $processes = $this->processes();
        $processes->shouldReceive('php')->andReturn([
            ['pid' => 10, 'parent' => 1, 'name' => 'php.exe', 'command' => 'php artisan horizon'],
            ['pid' => 12, 'parent' => 11, 'name' => 'php.exe', 'command' => 'php artisan horizon:work redis --supervisor=foo'],
            ['pid' => 15, 'parent' => 1, 'name' => 'php.exe', 'command' => 'php artisan queue:work'],
        ]);

        $control = $this->control($processes);

        $this->assertSame([10, 12], $control->find('[h]orizon'));
        $this->assertSame('php artisan horizon:work redis --supervisor=foo', $control->listing('horizon:work'));
    }

    public function test_parent_checks_are_throttled()
    {
        $processes = $this->processes();
        $processes->shouldReceive('isRunning')->once()->with(123)->andReturn(false);

        $control = $this->control($processes);

        $this->assertFalse($control->parentIsRunning(123));
        $this->assertFalse($control->parentIsRunning(123));
    }

    public function test_workers_receive_signals_when_looping()
    {
        $control = $this->control();
        $worker = Mockery::mock(Worker::class)->makePartial();

        $control->watchWorker($worker, 60);
        $this->assertNull(event(new Looping('redis', 'default'), halt: true));

        $control->mailbox->send('pid:'.getmypid(), SIGUSR2);
        $this->assertFalse(event(new Looping('redis', 'default'), halt: true));
        $this->assertFalse(event(new Looping('redis', 'default'), halt: true));

        $control->mailbox->send('pid:'.getmypid(), SIGCONT);
        $control->mailbox->send('pid:'.getmypid(), SIGTERM);
        $this->assertNull(event(new Looping('redis', 'default'), halt: true));

        $this->assertFalse($worker->paused);
        $this->assertTrue($worker->shouldQuit);
    }

    public function test_workers_started_paused_keep_looping_while_paused()
    {
        $control = $this->control();
        $worker = Mockery::mock(Worker::class)->makePartial();
        $worker->paused = true;

        $control->watchWorker($worker, 60);

        $this->assertFalse($worker->paused);
        $this->assertFalse(event(new Looping('redis', 'default'), halt: true));

        $control->mailbox->send('pid:'.getmypid(), SIGCONT);
        $this->assertNull(event(new Looping('redis', 'default'), halt: true));
    }

    public function test_workers_past_their_job_deadline_are_stopped()
    {
        $control = $this->control();
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isRunning')->andReturn(true);
        $process->shouldReceive('getCommandLine')->andReturn('set "HORIZON_PROCESS_ID=abc123" && php artisan horizon:work');

        putenv(WindowsProcessControl::PROCESS_ID.'=abc123');
        $control->watchWorker(Mockery::mock(Worker::class)->makePartial(), 1);

        $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('timeout')->andReturn(null);
        event(new JobProcessing('redis', $job));

        $control->monitorProcess($process);

        $this->travel(2)->seconds();
        $process->shouldReceive('stop')->once()->with(0);
        $control->monitorProcess($process);
    }

    public function test_job_deadlines_are_cleared_between_jobs()
    {
        $control = $this->control();
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('isRunning')->andReturn(true);
        $process->shouldReceive('getCommandLine')->andReturn('set "HORIZON_PROCESS_ID=abc123" && php artisan horizon:work');
        $process->shouldNotReceive('stop');

        putenv(WindowsProcessControl::PROCESS_ID.'=abc123');
        $control->watchWorker(Mockery::mock(Worker::class)->makePartial(), 1);

        $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
        $job->shouldReceive('timeout')->andReturn(null);
        event(new JobProcessing('redis', $job));
        event(new Looping('redis', 'default'));

        $this->travel(2)->seconds();
        $control->monitorProcess($process);
    }

    protected function control($processes = null)
    {
        return new WindowsProcessControl(
            resolve(SignalMailbox::class),
            $processes ?? $this->processes(),
            resolve(RedisFactory::class)
        );
    }

    protected function processes()
    {
        return Mockery::mock(WindowsProcessTable::class);
    }
}

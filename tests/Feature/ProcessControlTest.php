<?php

namespace Laravel\Horizon\Tests\Feature;

use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\ProcessControl;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\PhpBinary;
use Laravel\Horizon\PosixProcessControl;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\SystemProcessCounter;
use Laravel\Horizon\Tests\IntegrationTest;
use Mockery;

class ProcessControlTest extends IntegrationTest
{
    public function test_posix_process_control_is_bound_by_default()
    {
        $this->assertInstanceOf(PosixProcessControl::class, resolve(ProcessControl::class));
    }

    public function test_supervisor_commands_send_signals_through_process_control()
    {
        $this->fakeSupervisor(123);

        $control = Mockery::mock(ProcessControl::class);
        $control->shouldReceive('signal')->once()->with(123, SIGUSR2)->andReturn(true);
        $control->shouldReceive('signal')->once()->with(123, SIGCONT)->andReturn(true);
        $this->app->instance(ProcessControl::class, $control);

        $this->artisan('horizon:pause-supervisor', ['name' => 'supervisor-1'])->assertExitCode(0);
        $this->artisan('horizon:continue-supervisor', ['name' => 'supervisor-1'])->assertExitCode(0);
    }

    public function test_failed_signals_report_the_process_control_error()
    {
        $this->fakeSupervisor(123);

        $control = Mockery::mock(ProcessControl::class);
        $control->shouldReceive('signal')->once()->with(123, SIGUSR2)->andReturn(false);
        $control->shouldReceive('lastError')->once()->andReturn('No such process');
        $this->app->instance(ProcessControl::class, $control);

        $this->artisan('horizon:pause-supervisor', ['name' => 'supervisor-1'])
            ->expectsOutputToContain('No such process');
    }

    public function test_system_process_counter_uses_the_process_listing()
    {
        SystemProcessCounter::$command = 'worker.php';

        $control = Mockery::mock(ProcessControl::class);
        $control->shouldReceive('listing')->once()->with('worker.php')->andReturn(implode("\n", [
            'php worker.php --supervisor=foo',
            'php worker.php --supervisor=foo',
            'php worker.php --supervisor=bar',
        ]));
        $this->app->instance(ProcessControl::class, $control);

        $this->assertSame(2, resolve(SystemProcessCounter::class)->get('foo'));
    }

    public function test_command_strings_are_prepared_by_process_control()
    {
        $control = Mockery::mock(ProcessControl::class);
        $control->shouldReceive('prepareCommand')->andReturnUsing(fn ($command) => Str::after($command, 'exec '));
        $this->app->instance(ProcessControl::class, $control);

        $options = new SupervisorOptions('name', 'redis');

        $this->assertStringStartsWith(PhpBinary::path().' artisan horizon:work', $options->toWorkerCommand());
        $this->assertStringStartsWith(PhpBinary::path().' artisan horizon:supervisor', $options->toSupervisorCommand());
    }

    public function test_posix_command_strings_are_unchanged()
    {
        $options = new SupervisorOptions('name', 'redis');

        $this->assertStringStartsWith('exec '.PhpBinary::path().' artisan horizon:work', $options->toWorkerCommand());
        $this->assertStringStartsWith('exec '.PhpBinary::path().' artisan horizon:supervisor', $options->toSupervisorCommand());
    }

    protected function fakeSupervisor($processId)
    {
        $supervisors = Mockery::mock(SupervisorRepository::class);
        $supervisors->shouldReceive('all')->andReturn([
            (object) ['name' => MasterSupervisor::basename().'-abcd:supervisor-1', 'pid' => $processId],
        ]);
        $this->app->instance(SupervisorRepository::class, $supervisors);
    }
}

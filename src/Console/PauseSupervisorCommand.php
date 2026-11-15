<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\ProcessControl;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:pause-supervisor')]
class PauseSupervisorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:pause-supervisor
                            {name : The name of the supervisor to pause}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause a supervisor';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\SupervisorRepository  $supervisors
     * @param  \Laravel\Horizon\Contracts\ProcessControl  $control
     * @return int|void
     */
    public function handle(SupervisorRepository $supervisors, ProcessControl $control)
    {
        $processId = optional(collect($supervisors->all())->first(function ($supervisor) {
            return Str::startsWith($supervisor->name, MasterSupervisor::basename())
                    && Str::endsWith($supervisor->name, $this->argument('name'));
        }))->pid;

        if (is_null($processId)) {
            $this->components->error('Failed to find a supervisor with this name');

            return 1;
        }

        $this->components->info("Sending USR2 signal to process: {$processId}");

        if (! $control->signal($processId, SIGUSR2)) {
            $this->components->error("Failed to send USR2 signal to process: {$processId} (".$control->lastError().')');
        }
    }
}

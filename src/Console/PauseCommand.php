<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\ProcessControl;
use Laravel\Horizon\MasterSupervisor;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:pause')]
class PauseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:pause';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pause the master supervisor';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @param  \Laravel\Horizon\Contracts\ProcessControl  $control
     * @return void
     */
    public function handle(MasterSupervisorRepository $masters, ProcessControl $control)
    {
        $masters = collect($masters->all())
            ->filter(fn ($master) => Str::startsWith($master->name, MasterSupervisor::basename()))
            ->all();

        collect(Arr::pluck($masters, 'pid'))
            ->whenNotEmpty(fn () => $this->components->info('Sending USR2 signal to processes.'))
            ->whenEmpty(fn () => $this->components->info('No processes to pause.'))
            ->each(function ($processId) use ($control) {
                $result = true;

                $this->components->task("Process: $processId", function () use ($processId, $control, &$result) {
                    return $result = $control->signal($processId, SIGUSR2);
                });

                if (! $result) {
                    $this->components->error("Failed to kill process: {$processId} (".$control->lastError().')');
                }
            })->whenNotEmpty(fn () => $this->output->writeln(''));
    }
}

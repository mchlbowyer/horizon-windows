<?php

namespace Laravel\Horizon;

use Illuminate\Support\Arr;
use Laravel\Horizon\Contracts\ProcessControl;

trait ListensForSignals
{
    /**
     * The pending signals that need to be processed.
     *
     * @var array
     */
    protected $pendingSignals = [];

    /**
     * Listen for incoming process signals.
     *
     * @return void
     */
    protected function listenForSignals()
    {
        app(ProcessControl::class)->listen([
            SIGTERM => function () {
                $this->pendingSignals['terminate'] = 'terminate';
            },
            SIGUSR1 => function () {
                $this->pendingSignals['restart'] = 'restart';
            },
            SIGUSR2 => function () {
                $this->pendingSignals['pause'] = 'pause';
            },
            SIGCONT => function () {
                $this->pendingSignals['continue'] = 'continue';
            },
        ]);
    }

    /**
     * Process the pending signals.
     *
     * @return void
     */
    protected function processPendingSignals()
    {
        while ($this->pendingSignals) {
            $signal = Arr::first($this->pendingSignals);

            $this->{$signal}();

            unset($this->pendingSignals[$signal]);
        }
    }
}

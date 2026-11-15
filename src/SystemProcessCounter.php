<?php

namespace Laravel\Horizon;

use Laravel\Horizon\Contracts\ProcessControl;

class SystemProcessCounter
{
    /**
     * The base command to search for.
     *
     * @var string
     */
    public static $command = 'horizon:work';

    /**
     * Get the number of Horizon workers for a given supervisor.
     *
     * @param  string  $name
     * @return int
     */
    public function get($name)
    {
        $listing = app(ProcessControl::class)->listing(static::$command);

        return substr_count($listing, 'supervisor='.$name);
    }
}

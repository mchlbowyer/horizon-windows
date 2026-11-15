<?php

// Horizon refers to process signals by their constants. Platforms without the
// pcntl extension (such as Windows) do not define them, so we define them
// here using their Linux values. Existing definitions are never replaced.
foreach ([
    'SIGINT' => 2,
    'SIGQUIT' => 3,
    'SIGKILL' => 9,
    'SIGUSR1' => 10,
    'SIGUSR2' => 12,
    'SIGTERM' => 15,
    'SIGCONT' => 18,
] as $name => $value) {
    if (! defined($name)) {
        define($name, $value);
    }
}

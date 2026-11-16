<?php

require __DIR__.'/../vendor/autoload.php';

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Laravel\Horizon\Contracts\ProcessControl;
use Laravel\Horizon\Tests\RedisClusterHelper;

use function Orchestra\Testbench\container;

// Windows cannot replace a file another process has open, so workers booting
// together would race to rewrite the shared services manifest. Give each
// worker its own manifest instead...
if (PHP_OS_FAMILY === 'Windows') {
    $services = 'bootstrap/cache/services-worker-'.getmypid().'.php';

    putenv("APP_SERVICES_CACHE={$services}");
    $_ENV['APP_SERVICES_CACHE'] = $_SERVER['APP_SERVICES_CACHE'] = $services;
}

// Configure the application...
$app = container()->createApplication();

if (PHP_OS_FAMILY === 'Windows') {
    register_shutdown_function(fn () => @unlink($app->getCachedServicesPath()));
}
$app->make('config')->set('queue.default', 'redis');

RedisClusterHelper::configure($app);

$worker = new Worker(
    $app->make(QueueManager::class),
    $app->make(Dispatcher::class),
    $app->make(ExceptionHandler::class),
    function () use ($app) {
        return $app->isDownForMaintenance();
    }
);

// Pause the worker if needed...
if (in_array('--paused', $_SERVER['argv'])) {
    $worker->paused = true;
}

// Let the worker receive signals where the platform cannot deliver them...
$app->make(ProcessControl::class)->watchWorker($worker, 60);

// Start the daemon loop.
$worker->daemon(
    'redis', 'default', new WorkerOptions
);

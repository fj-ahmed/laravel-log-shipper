<?php

namespace FjAhmed\LogShipper;

use FjAhmed\LogShipper\Console\RecordRuns;
use FjAhmed\LogShipper\Http\LogRequests;
use FjAhmed\LogShipper\Logging\CollectorHandler;
use FjAhmed\LogShipper\Logging\LogTap;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger;

/**
 * Everything is opted in from config/logging.php, per channel:
 *
 *   'microservice' => ['driver' => 'shipper'],                                  // send only, no file
 *   'twilio'       => ['driver' => 'daily', 'path' => ..., 'shipper' => true],  // keep the file AND send
 *   'webservice'   => ['driver' => 'shipper', 'requests' => ['api'], 'paths' => [...], 'except' => [...], 'ajax' => bool], // see LogRequests
 *   'runs'         => ['driver' => 'shipper', 'runs' => true],                 // artisan commands (crons) + queue jobs
 *
 * Stored channel name = 'shipper' => '<name>' if given, else the file name of 'path' (logs/twilio.log -> twilio),
 * else the channel key. Connection settings come from .env (LOG_SHIPPER_*), see config/log-shipper.php.
 */
class LogShipperServiceProvider extends ServiceProvider
{
    /** @var array<string, array{label: string, config: array}> opted-in channels */
    private array $channels = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/log-shipper.php', 'log-shipper');

        $this->app->singleton(Collector::class, fn($app) => new Collector(
            $app['config']['log-shipper'],
            new Masker($app['config']['log-shipper.mask_keys'], $app['config']['log-shipper.mask_exact_keys']),
        ));

        // Runs in register() so the driver and taps exist before any channel is resolved.
        $this->callAfterResolving('log', fn($log) => $log->extend('shipper', fn($app, array $config) => new Logger(
            $config['shipper_label'] ?? 'shipper',
            [new CollectorHandler($app->make(Collector::class), $config['shipper_label'] ?? 'shipper', strtolower($config['level'] ?? 'debug'))],
        )));

        $config = $this->app['config'];
        foreach ((array) $config['logging.channels'] as $name => $channel) {
            $isDriver = ($channel['driver'] ?? null) === 'shipper';
            if (!$isDriver && empty($channel['shipper'])) {
                continue;
            }
            $label = is_string($channel['shipper'] ?? null) ? $channel['shipper']
                : (isset($channel['path']) ? preg_replace('/\.log$/', '', basename($channel['path'])) : $name);
            $this->channels[$name] = ['label' => $label, 'config' => $channel];
            if (!empty($channel['requests'])) {
                $config->set("log-shipper.request_channels.$label", $channel); // read by LogRequests, in file order
            }

            if ($isDriver) {
                $config->set("logging.channels.$name.shipper_label", $label); // custom drivers aren't told their name
            } else {
                $level = strtolower($channel['level'] ?? 'debug');
                $config->set("logging.channels.$name.tap", array_merge($channel['tap'] ?? [], [LogTap::class . ":$label,$level"]));
            }
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/log-shipper.php' => config_path('log-shipper.php')], 'log-shipper');
        if (!$this->channels || !config('log-shipper.enabled') || !config('log-shipper.project')) {
            return;
        }

        if (config('log-shipper.request_channels')) {
            // Global, so it sees every route; logging.php decides which requests are kept (LogRequests::channelFor).
            $this->callAfterResolving(HttpKernel::class, fn($kernel) => $kernel->pushMiddleware(LogRequests::class));
        }

        foreach ($this->channels as ['label' => $label, 'config' => $channel]) {
            if (!empty($channel['runs'])) {
                $this->listenForRuns($label);
            }
        }

        $flush = fn() => $this->app->make(Collector::class)->flush();
        $this->app->terminating($flush);    // after the response is sent / the command ends
        register_shutdown_function($flush); // fatal errors and timeouts that skip terminating()
        Event::listen([JobProcessed::class, JobFailed::class], $flush); // long-running queue workers
    }

    private function listenForRuns(string $label): void
    {
        $this->app->singleton(RecordRuns::class, fn($app) => new RecordRuns($app->make(Collector::class), $label));
        Event::listen(CommandStarting::class, [RecordRuns::class, 'commandStarting']);
        Event::listen(CommandFinished::class, [RecordRuns::class, 'commandFinished']);
        Event::listen(JobProcessing::class, [RecordRuns::class, 'jobProcessing']);
        Event::listen(JobProcessed::class, [RecordRuns::class, 'jobProcessed']);
        Event::listen([JobFailed::class, JobExceptionOccurred::class], [RecordRuns::class, 'jobFailed']);
    }
}

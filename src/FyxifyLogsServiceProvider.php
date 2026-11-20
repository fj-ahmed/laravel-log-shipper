<?php

namespace FyxifyLogs;

use FyxifyLogs\Console\RecordRuns;
use FyxifyLogs\Http\LogRequests;
use FyxifyLogs\Logging\CollectorHandler;
use FyxifyLogs\Logging\LogTap;
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
 *   'microservice' => ['driver' => 'fyxify'],                                  // send only, no file
 *   'twilio'       => ['driver' => 'daily', 'path' => ..., 'fyxify' => true],  // keep the file AND send
 *   'webservice'   => ['driver' => 'fyxify', 'requests' => ['api'], 'paths' => [...], 'except' => [...], 'ajax' => bool], // see LogRequests
 *   'runs'         => ['driver' => 'fyxify', 'runs' => true],                 // artisan commands (crons) + queue jobs
 *
 * Stored channel name = 'fyxify' => '<name>' if given, else the file name of 'path' (logs/twilio.log -> twilio),
 * else the channel key. Connection settings come from .env (FYXIFY_LOGS_*), see config/fyxify-logs.php.
 */
class FyxifyLogsServiceProvider extends ServiceProvider
{
    /** @var array<string, array{label: string, config: array}> opted-in channels */
    private array $channels = [];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fyxify-logs.php', 'fyxify-logs');

        $this->app->singleton(Collector::class, fn($app) => new Collector(
            $app['config']['fyxify-logs'],
            new Masker($app['config']['fyxify-logs.mask_keys'], $app['config']['fyxify-logs.mask_exact_keys']),
        ));

        // Runs in register() so the driver and taps exist before any channel is resolved.
        $this->callAfterResolving('log', fn($log) => $log->extend('fyxify', fn($app, array $config) => new Logger(
            $config['fyxify_label'] ?? 'fyxify',
            [new CollectorHandler($app->make(Collector::class), $config['fyxify_label'] ?? 'fyxify', strtolower($config['level'] ?? 'debug'))],
        )));

        $config = $this->app['config'];
        foreach ((array) $config['logging.channels'] as $name => $channel) {
            $isDriver = ($channel['driver'] ?? null) === 'fyxify';
            if (!$isDriver && empty($channel['fyxify'])) {
                continue;
            }
            $label = is_string($channel['fyxify'] ?? null) ? $channel['fyxify']
                : (isset($channel['path']) ? preg_replace('/\.log$/', '', basename($channel['path'])) : $name);
            $this->channels[$name] = ['label' => $label, 'config' => $channel];
            if (!empty($channel['requests'])) {
                $config->set("fyxify-logs.request_channels.$label", $channel); // read by LogRequests, in file order
            }

            if ($isDriver) {
                $config->set("logging.channels.$name.fyxify_label", $label); // custom drivers aren't told their name
            } else {
                $level = strtolower($channel['level'] ?? 'debug');
                $config->set("logging.channels.$name.tap", array_merge($channel['tap'] ?? [], [LogTap::class . ":$label,$level"]));
            }
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/fyxify-logs.php' => config_path('fyxify-logs.php')], 'fyxify-logs');
        if (!$this->channels || !config('fyxify-logs.enabled') || !config('fyxify-logs.project')) {
            return;
        }

        if (config('fyxify-logs.request_channels')) {
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

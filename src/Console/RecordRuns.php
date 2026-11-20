<?php

namespace FyxifyLogs\Console;

use FyxifyLogs\Collector;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns every artisan command (crons) and queue job into a "run" row in request_logs:
 * method CLI|JOB, url = command line / job class, status = exit code (0 ok, 1 failed), duration.
 * Log:: lines written during the run carry its request_id, so the UI shows them under the run.
 */
class RecordRuns
{
    private array $runs = [];

    public function __construct(private Collector $collector, private string $channel = 'runs')
    {
    }

    public function commandStarting(CommandStarting $e): void
    {
        if ($this->skip($e->command)) {
            return;
        }
        $this->start('cli:' . $e->command, $this->channel, 'CLI', 'artisan ' . $this->commandLine($e), null);
    }

    public function commandFinished(CommandFinished $e): void
    {
        $this->finish('cli:' . $e->command, $e->exitCode);
    }

    public function jobProcessing(JobProcessing $e): void
    {
        $this->start('job:' . $e->job->getJobId(), $this->channel, 'JOB', $e->job->resolveName(), [
            'queue' => $e->job->getQueue(), 'connection' => $e->connectionName, 'attempt' => $e->job->attempts(),
        ]);
    }

    public function jobProcessed(JobProcessed $e): void
    {
        $this->finish('job:' . $e->job->getJobId(), $e->job->hasFailed() ? 1 : 0);
    }

    public function jobFailed(JobFailed|JobExceptionOccurred $e): void
    {
        $this->finish('job:' . $e->job->getJobId(), 1, $e->exception);
    }

    private function start(string $key, string $channel, string $method, string $url, ?array $input): void
    {
        $id = (string) Str::uuid();
        $this->runs[$key] = [
            'started'  => microtime(true),
            'previous' => $this->collector->requestId(),
            'entry'    => [
                'request_id' => $id,
                'created_at' => now()->format('Y-m-d H:i:s.v'),
                'channel'    => $channel,
                'method'     => $method,
                'url'        => $this->collector->masker->maskString(Str::limit($url, 2000)),
                'path'       => Str::limit(strtok($url . ' ', ' '), 400, ''),
                'route_name' => null,
                'ip'         => null,
                'user_id'    => null,
                'source'     => gethostname() ?: null,
                'headers'    => null,
                'input'      => $input ? json_encode($this->collector->masker->mask($input)) : null,
            ],
        ];
        $this->collector->setRequestId($id);
    }

    private function finish(string $key, ?int $exitCode, ?Throwable $e = null): void
    {
        if (!isset($this->runs[$key])) {
            return;
        }
        $run = $this->runs[$key];
        unset($this->runs[$key]);

        $this->collector->addRequest($run['entry'] + [
            'status'      => $exitCode ?? 0,
            'duration_ms' => (int) round((microtime(true) - $run['started']) * 1000),
            'output'      => $e ? $this->collector->masker->maskString(get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()) : null,
        ]);
        $this->collector->setRequestId($run['previous']);
        $this->collector->flush();
    }

    private function commandLine(CommandStarting $e): string
    {
        // ArgvInput::__toString() gives "'cmd' --opt=val"; keep it readable.
        return str_replace("'", '', (string) $e->input) ?: $e->command;
    }

    private function skip(?string $command): bool
    {
        return !$command || Str::is(config('fyxify-logs.except_commands', []), $command);
    }
}

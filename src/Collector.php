<?php

namespace FyxifyLogs;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Buffers one request's (or one job's) log entries and ships them in a single POST when the app terminates.
 * If the service is unreachable the batch goes to storage/logs/fyxify-logs-pending-Y-m-d.ndjson
 * (re-ingest with `npm run replay -- <file>` in fyxify-logs). Nothing is dropped.
 */
class Collector
{
    private array $requests = [];
    private array $logs = [];
    private ?string $requestId = null;
    private bool $flushing = false;
    private float $lastFlush;

    public function __construct(private array $config, public readonly Masker $masker)
    {
        $this->lastFlush = microtime(true);
    }

    public function setRequestId(?string $id): void
    {
        $this->requestId = $id;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function isFlushing(): bool
    {
        return $this->flushing;
    }

    public function addRequest(array $entry): void
    {
        $this->requests[] = $entry;
    }

    public function addLog(array $entry): void
    {
        $this->logs[] = $entry;
        // Long-running crons / queue workers: ship every 5s (or 200 lines) so they can be watched live.
        if (count($this->logs) >= 200 || microtime(true) - $this->lastFlush >= 5) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->flushing || (!$this->requests && !$this->logs)) {
            return;
        }
        $payload = ['requests' => $this->requests, 'logs' => $this->logs];
        $this->requests = $this->logs = [];
        $this->flushing = true;
        $this->lastFlush = microtime(true);

        try {
            $response = Http::connectTimeout(1)
                ->timeout($this->config['timeout'])
                ->withHeaders(['X-Api-Key' => (string) $this->config['key']])
                ->acceptJson()
                ->post(rtrim($this->config['url'], '/') . '/ingest/' . $this->config['project'], $payload);
            $sent = $response->successful();
        } catch (Throwable) {
            $sent = false;
        }

        if (!$sent) {
            $line = json_encode(['project' => $this->config['project']] + $payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            @file_put_contents(storage_path('logs/fyxify-logs-pending-' . date('Y-m-d') . '.ndjson'), $line . "\n", FILE_APPEND | LOCK_EX);
        }
        $this->flushing = false;
    }
}

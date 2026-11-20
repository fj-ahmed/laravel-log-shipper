<?php

namespace FyxifyLogs\Logging;

use FyxifyLogs\Collector;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use WeakMap;

class CollectorHandler extends AbstractProcessingHandler
{
    // A stack channel (e.g. single + daily) runs one record through several taps; store it once.
    private static ?WeakMap $seen = null;

    public function __construct(private Collector $collector, private string $channel, string $level)
    {
        parent::__construct(Level::fromName($level));
    }

    public function handle(LogRecord $record): bool
    {
        self::$seen ??= new WeakMap();
        if ($this->collector->isFlushing() || isset(self::$seen[$record])) {
            return false;
        }
        self::$seen[$record] = true;
        return parent::handle($record);
    }

    protected function write(LogRecord $record): void
    {
        $normalized = (new NormalizerFormatter('Y-m-d H:i:s.v'))->format($record);
        $masker = $this->collector->masker;
        $json = fn($v) => $v ? json_encode($masker->mask($v), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;

        $this->collector->addLog([
            'request_id' => $this->collector->requestId(),
            'created_at' => $record->datetime->format('Y-m-d H:i:s.v'),
            'channel'    => $this->channel,
            'level'      => strtolower($record->level->getName()),
            'message'    => $masker->maskString($record->message),
            'context'    => $json($normalized['context'] ?? []),
            'extra'      => $json($normalized['extra'] ?? []),
        ]);
    }
}

<?php

namespace FjAhmed\LogShipper\Logging;

use FjAhmed\LogShipper\Collector;

/** Added to every logging channel by the service provider: 'tap' => [LogTap::class.':microservice,debug'] */
class LogTap
{
    public function __construct(private Collector $collector)
    {
    }

    public function __invoke($logger, string $channel = 'laravel', string $level = 'debug'): void
    {
        $logger->pushHandler(new CollectorHandler($this->collector, $channel, $level));
    }
}

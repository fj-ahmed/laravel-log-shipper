<?php

namespace FyxifyLogs\Http;

use Closure;
use FyxifyLogs\Collector;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Global middleware, added by the service provider. Which requests are recorded, and under which channel, is
 * declared per channel in config/logging.php (first match wins, in file order):
 *
 *   'admin_webservice' => ['driver' => 'fyxify', 'requests' => ['web'], 'paths' => ['backend/microservices/*']],
 *   'web-portal'       => ['driver' => 'fyxify', 'requests' => ['web'], 'ajax' => true],
 *   'webservice'       => ['driver' => 'fyxify', 'requests' => ['api'], 'except' => ['*profile/online/status']],
 *
 * 'requests' = middleware groups, aliases or classes on the route (e.g. 'api', 'api.logs', WebserviceLogger::class).
 */
class LogRequests
{
    public function __construct(private Collector $collector)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Honour an incoming X-Request-Id (e.g. fyxify -> msnotifications) so calls link across services.
        $incoming = (string) $request->header('X-Request-Id');
        $this->collector->setRequestId(Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid());

        $response = $next($request);
        $response->headers->set('X-Request-Id', $this->collector->requestId());

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($channel = self::channelFor($request, config('fyxify-logs.request_channels', []))) {
                $this->record($request, $response, $channel);
            }
        } catch (Throwable) {
            // Logging must never break the app.
        }
    }

    /** @param array<string, array> $channels label => logging.php channel config */
    public static function channelFor(Request $request, array $channels): ?string
    {
        $route = $request->route();
        if (!is_object($route)) {
            return null; // 404s and other unrouted requests: the old loggers never saw them either
        }
        $names = fn(array $list) => array_map(fn($m) => is_string($m) ? strtok($m, ':') : '', $list);
        $onRoute = array_merge($names($route->gatherMiddleware()), $names(app('router')->gatherRouteMiddleware($route)));

        foreach ($channels as $label => $c) {
            if (!array_intersect((array) $c['requests'], $onRoute)) {
                continue;
            }
            if (!empty($c['paths']) && !$request->is(...(array) $c['paths'])) {
                continue;
            }
            if (!empty($c['except']) && $request->is(...(array) $c['except'])) {
                continue;
            }
            if (isset($c['ajax']) && $request->ajax() !== (bool) $c['ajax']) {
                continue;
            }
            return $label;
        }
        return null;
    }

    private function record(Request $request, Response $response, string $channel): void
    {
        $masker  = $this->collector->masker;
        $started = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $route   = $request->route();

        $this->collector->addRequest([
            'request_id'  => $this->collector->requestId(),
            'created_at'  => \DateTime::createFromFormat('U.u', sprintf('%.6F', $started))
                ->setTimezone(new \DateTimeZone(config('app.timezone')))->format('Y-m-d H:i:s.v'),
            'channel'     => $channel,
            'method'      => $request->method(),
            'url'         => $masker->maskString($request->fullUrl()),
            'path'        => '/' . ltrim($request->path(), '/'),
            'route_name'  => $route->getName() ?? $route->uri(),
            'status'      => $response->getStatusCode(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ip'          => $request->getClientIp(),
            'user_id'     => $this->userId(config('fyxify-logs.guards', [])),
            'source'      => $request->header('X-Fx-Source', $request->getHost()),
            'headers'     => $this->json($masker->mask($request->headers->all())),
            'input'       => $this->json($masker->mask($this->input($request))),
            'output'      => $masker->maskString($this->output($response, (int) config('fyxify-logs.max_output'))),
        ]);
    }

    private function input(Request $request): array
    {
        $input = $request->all();
        array_walk_recursive($input, function (&$v) {
            if ($v instanceof UploadedFile) {
                $v = ['file' => $v->getClientOriginalName(), 'size' => $v->getSize(), 'mime' => $v->getClientMimeType()];
            }
        });
        // Non-form bodies (XML, raw text) still get recorded.
        if (!$input && ($raw = $request->getContent()) !== '') {
            $input = ['_raw' => Str::limit($raw, 65536)];
        }
        return $input;
    }

    private function output(Response $response, int $max): string
    {
        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return '[' . class_basename($response) . ' ' . $response->headers->get('Content-Type') . ']';
        }
        $content = (string) $response->getContent();
        return strlen($content) > $max ? substr($content, 0, $max) . '…[truncated ' . strlen($content) . ' bytes]' : $content;
    }

    private function userId(array $guards): ?string
    {
        foreach ($guards as $guard) {
            try {
                $g = auth()->guard($guard);
                if (method_exists($g, 'hasUser') && $g->hasUser()) {
                    return (string) $g->id();
                }
            } catch (Throwable) {
                // guard not configured in this app
            }
        }
        return null;
    }

    private function json(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}

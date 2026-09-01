<?php

namespace App\Http\Middleware;

use App\Support\ApiLogRedactor;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Records one api_request_logs row per /api/v1 request. handle() is a pure
 * pass-through that only stamps a start time; the row is written in terminate(),
 * which runs AFTER the response is flushed to the client — zero added latency.
 *
 * A logging failure must never surface to the caller: terminate() is fully
 * wrapped in try/catch and errors go only to the 'errors' log channel.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('api.logging.enabled')) {
            $request->attributes->set('api_log_started_at', hrtime(true));
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! config('api.logging.enabled')) {
            return;
        }

        try {
            $this->record($request, $response);
        } catch (\Throwable $e) {
            Log::channel('errors')->error('api-request-log write failed: '.$e->getMessage(), [
                'exception' => get_class($e),
            ]);
        }
    }

    private function record(Request $request, Response $response): void
    {
        $startedAt = $request->attributes->get('api_log_started_at');
        $durationMs = $startedAt ? (int) round((hrtime(true) - $startedAt) / 1_000_000) : 0;

        $user = $request->user();
        $token = $user?->currentAccessToken();
        $tokenId = ($token && isset($token->id)) ? $token->id : null;
        $tokenName = ($token && isset($token->name)) ? $token->name : null;

        $status = $response->getStatusCode();
        $captureBody = $status >= 400
            || (mt_rand() / mt_getrandmax()) < (float) config('api.logging.success_sample_rate');

        // --- Response body / size ---
        $responseBody = null;
        $responseSize = null;

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            $responseBody = $captureBody ? '[streamed response]' : null;
        } else {
            $content = $response->getContent();
            $responseSize = $content === false ? null : strlen($content);
            if ($captureBody && $content !== false) {
                $prepared = ApiLogRedactor::bodyForStorage(
                    $content,
                    $response->headers->get('Content-Type')
                );
                $responseBody = $prepared['body'] ?? null;
            }
        }

        // --- Request body ---
        $requestBody = null;
        if ($captureBody) {
            $prepared = ApiLogRedactor::bodyForStorage(
                $request->getContent() ?: null,
                $request->headers->get('Content-Type')
            );
            $requestBody = $prepared['body'] ?? null;
        }

        // --- Query params (always, redacted) ---
        $query = $request->query();
        $query = $query ? ApiLogRedactor::redactArray($query) : null;

        // --- Error class, if the framework rendered an exception ---
        // Laravel attaches the caught exception to the RESPONSE (via
        // Illuminate\Http\ResponseTrait::withException), not the request. A
        // plain Symfony Response has no `exception` property, hence the isset().
        $errorClass = null;
        if (isset($response->exception) && $response->exception instanceof \Throwable) {
            $errorClass = get_class($response->exception);
        }

        DB::table('api_request_logs')->insert([
            'client_id' => $user?->client_id,
            'user_id' => $user?->id,
            'token_id' => $tokenId,
            'token_name' => $tokenName ? Str::limit($tokenName, 250, '') : null,
            'method' => $request->getMethod(),
            'path' => Str::limit($request->path(), 2048, ''),
            'route_name' => $request->route()?->getName(),
            'query' => $query ? json_encode($query) : null,
            'status' => $status,
            'duration_ms' => max($durationMs, 0),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1024, ''),
            'request_headers' => json_encode(ApiLogRedactor::redactHeaders($request->headers->all())),
            'request_body' => $requestBody,
            'response_body' => $responseBody,
            'response_size_bytes' => $responseSize,
            'error_class' => $errorClass,
            'created_at' => now(),
        ]);
    }
}

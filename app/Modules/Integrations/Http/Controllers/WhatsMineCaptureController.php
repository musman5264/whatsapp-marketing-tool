<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TEMPORARY capture endpoint for WhatsMine webhooks (T-0006, spec: WHATSMINE-CAPTURE-SPEC.md).
 *
 * Writes each delivery to storage/app/whatsmine-capture/ exactly as it arrived (the raw body is
 * stored base64-encoded). It does not verify signatures, change data or send messages.
 *
 * Active only when WHATSMINE_CAPTURE_ENABLED=true and the URL carries WHATSMINE_CAPTURE_TOKEN.
 * Captured files contain phone numbers and message text: keep them local and delete them after use.
 */
class WhatsMineCaptureController extends Controller
{
    /** Headers that carry credentials and must never be written to disk. */
    private const SECRET_HEADERS = ['authorization', 'cookie', 'x-csrf-token'];

    public function receive(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('whatsmine_capture.token');

        if (! config('whatsmine_capture.enabled') || $expected === '' || ! hash_equals($expected, $token)) {
            abort(404);
        }

        $dir = (string) config('whatsmine_capture.dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $raw = $request->getContent();

        $record = [
            'received_at' => now()->toIso8601String(),
            'method' => $request->method(),
            'headers' => $this->safeHeaders($request),
            'raw_body_base64' => base64_encode($raw),
            'raw_body_text' => mb_check_encoding($raw, 'UTF-8') ? $raw : null,
        ];

        $name = now()->format('Ymd-His-u').'-'.Str::random(6).'.json';

        file_put_contents(
            $dir.DIRECTORY_SEPARATOR.$name,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        );

        return response()->json(['ok' => true]);
    }

    /** @return array<string, array<int, string>> */
    private function safeHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), self::SECRET_HEADERS, true)) {
                continue;
            }

            $headers[strtolower($name)] = $values;
        }

        return $headers;
    }
}

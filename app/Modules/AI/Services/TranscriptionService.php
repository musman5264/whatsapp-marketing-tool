<?php

namespace App\Modules\AI\Services;

use App\Models\Workspace;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transcribes a customer voice note to text using whichever AI provider the
 * workspace has configured. Order: OpenAI (Whisper) → Gemini (inline audio) →
 * Cloudflare (@cf/openai/whisper). Returns null when nothing is available or the
 * call fails — callers should degrade gracefully.
 */
class TranscriptionService
{
    /** Max audio we will download and send (bytes). WhatsApp voice notes are small. */
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function transcribe(int $workspaceId, string $audioUrl): ?string
    {
        if ($audioUrl === '') {
            return null;
        }

        try {
            $resp = Http::timeout(30)->get($audioUrl);
            if (! $resp->successful()) {
                Log::warning('transcription.download_failed', ['status' => $resp->status(), 'url' => $audioUrl]);

                return null;
            }
            $audio = $resp->body();
            if (strlen($audio) === 0 || strlen($audio) > self::MAX_BYTES) {
                return null;
            }
            $contentType = $resp->header('Content-Type') ?: 'audio/ogg';
        } catch (\Throwable $e) {
            Log::warning('transcription.download_error', ['error' => $e->getMessage()]);

            return null;
        }

        $workspace = app(Workspace::class)->find($workspaceId);

        foreach ($this->candidateProviders($workspaceId) as $provider) {
            $creds = $workspace ? CredentialResolver::for($workspace)->llm($provider) : null;
            $data = $creds?->toArray();
            if (! $data) {
                continue;
            }

            try {
                $text = match ($provider) {
                    'openai' => $this->viaOpenAi($data, $audio, $contentType),
                    'gemini' => $this->viaGemini($data, $audio, $contentType),
                    'cloudflare' => $this->viaCloudflare($data, $audio),
                    default => null,
                };
            } catch (\Throwable $e) {
                Log::warning('transcription.provider_failed', ['provider' => $provider, 'error' => $e->getMessage()]);
                $text = null;
            }

            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return null;
    }

    /** @return string[] */
    private function candidateProviders(int $workspaceId): array
    {
        // Prefer whatever the workspace has enabled, then fall back to the fixed order.
        $enabled = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->pluck('provider')
            ->all();

        $order = ['openai', 'gemini', 'cloudflare'];

        return array_values(array_unique(array_merge(
            array_values(array_intersect($enabled, $order)),
            $order,
        )));
    }

    /** @param array<string,mixed> $creds */
    private function viaOpenAi(array $creds, string $audio, string $contentType): ?string
    {
        $key = (string) ($creds['api_key'] ?? '');
        if ($key === '') {
            return null;
        }

        $ext = str_contains($contentType, 'mp3') || str_contains($contentType, 'mpeg') ? 'mp3'
            : (str_contains($contentType, 'wav') ? 'wav'
            : (str_contains($contentType, 'm4a') || str_contains($contentType, 'mp4') ? 'm4a' : 'ogg'));

        $resp = Http::withToken($key)
            ->timeout(90)
            ->attach('file', $audio, "voice.{$ext}")
            ->post('https://api.openai.com/v1/audio/transcriptions', [
                'model' => 'whisper-1',
            ]);

        return $resp->successful() ? (string) $resp->json('text') : null;
    }

    /** @param array<string,mixed> $creds */
    private function viaGemini(array $creds, string $audio, string $contentType): ?string
    {
        $key = (string) ($creds['api_key'] ?? '');
        if ($key === '') {
            return null;
        }

        $mime = match (true) {
            str_contains($contentType, 'mp3'), str_contains($contentType, 'mpeg') => 'audio/mp3',
            str_contains($contentType, 'wav') => 'audio/wav',
            str_contains($contentType, 'm4a'), str_contains($contentType, 'mp4') => 'audio/mp4',
            str_contains($contentType, 'aac') => 'audio/aac',
            default => 'audio/ogg',
        };

        $resp = Http::timeout(90)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$key}",
            [
                'contents' => [[
                    'parts' => [
                        ['text' => 'Transcribe this audio to plain text. Output only the transcription, nothing else.'],
                        ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($audio)]],
                    ],
                ]],
                'generationConfig' => ['maxOutputTokens' => 1024],
            ],
        );

        return $resp->successful()
            ? (string) ($resp->json('candidates.0.content.parts.0.text') ?? '')
            : null;
    }

    /** @param array<string,mixed> $creds */
    private function viaCloudflare(array $creds, string $audio): ?string
    {
        $accountId = (string) ($creds['account_id'] ?? '');
        $keys = \App\Modules\AI\Services\Llm\CloudflareProvider::extractKeys($creds);
        if ($accountId === '' || empty($keys)) {
            return null;
        }

        foreach ($keys as $key) {
            $resp = Http::withToken($key)
                ->timeout(90)
                ->withBody($audio, 'application/octet-stream')
                ->post("https://api.cloudflare.com/client/v4/accounts/{$accountId}/ai/run/@cf/openai/whisper");

            if ($resp->successful()) {
                $text = $resp->json('result.text');
                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        return null;
    }
}

<?php

namespace App\Modules\Broadcasting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Services\Llm\LlmManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailAiController extends Controller
{
    public function improveSubject(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:300'],
            'body' => ['nullable', 'string', 'max:8000'],
        ]);

        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        try {
            $llm = LlmManager::forWorkspace($workspaceId);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'No AI provider configured. Set one up in AI → Providers.'], 422);
        }

        $bodySnippet = '';
        if (! empty($validated['body'])) {
            $plain = strip_tags($validated['body']);
            $bodySnippet = "\nEmail body context (first 300 chars): ".mb_substr($plain, 0, 300);
        }

        $systemPrompt = <<<'PROMPT'
You are an expert email copywriter. Your only task is to write short email subject lines — the text that appears in an inbox before opening an email.

Output EXACTLY 3 lines numbered like this, and nothing else:
1. <subject line>
2. <subject line>
3. <subject line>

═══ EXAMPLE INPUT ═══
Current subject: Welcome to Acme

═══ EXAMPLE OUTPUT ═══
1. Your Acme journey starts today 🎉
2. Ready to get started? Your account awaits
3. Welcome, {{contact.first_name}} — here's what's next

═══ EXAMPLE INPUT ═══
Current subject: 50% off this weekend only

═══ EXAMPLE OUTPUT ═══
1. Save 50% before Sunday — don't miss out
2. Is this the best deal we've ever offered?
3. 50% off ends Sunday — grab yours now

Rules:
- Each subject line must be under 60 characters of plain text.
- Line 1: highlight the main benefit or offer.
- Line 2: curiosity or question that makes the reader want to open.
- Line 3: direct, urgent, and clear.
- A subject line is a SHORT PHRASE — never a sentence explaining strategy.
- Preserve any {{contact.field}} tokens if present in the original.
- No markdown, no bold (**), no explanations — just the 3 subject lines.
PROMPT;

        $userMessage = "Current subject: {$validated['subject']}{$bodySnippet}";

        try {
            $response = $llm->chat(
                [['role' => 'user', 'content' => $userMessage]],
                ['system' => $systemPrompt, 'max_tokens' => 200],
            );

            $content = trim($response->content);
            $suggestions = $this->parseSubjectSuggestions($content);

            if (count($suggestions) === 0) {
                return response()->json(['error' => 'Could not generate subject lines. Please try again.'], 422);
            }

            return response()->json(['suggestions' => array_slice($suggestions, 0, 3)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI suggestion failed: '.$e->getMessage()], 500);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'campaign_name' => ['nullable', 'string', 'max:128'],
            'tone' => ['nullable', 'in:professional,friendly,urgent,informative'],
        ]);

        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        try {
            $llm = LlmManager::forWorkspace($workspaceId);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => 'No AI provider configured. Set one up in AI → Providers.'], 422);
        }

        $tone = $validated['tone'] ?? 'professional';
        $campaignCtx = $validated['campaign_name'] ? "Campaign name: \"{$validated['campaign_name']}\"." : '';

        $systemPrompt = <<<PROMPT
You are an email copywriter. Your ONLY job is to write the text of a single marketing or transactional email that will be sent to recipients.

You must respond with EXACTLY two sections — nothing else before, between, or after:

SUBJECT: <one subject line, plain text, max 60 chars>
BODY:
<email body using only HTML tags with inline style= attributes>

═══ EXAMPLE INPUT ═══
Write a welcome email for new customers who just signed up.

═══ EXAMPLE OUTPUT ═══
SUBJECT: Welcome aboard, {{contact.first_name}}!
BODY:
<p style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;margin:0 0 14px;">Hi {{contact.first_name}},</p>
<p style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;margin:0 0 14px;">Welcome! We're thrilled to have you with us. Your account is ready and you can start exploring right away.</p>
<p style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;margin:0 0 20px;">Here's what you can do first:</p>
<ul style="font-family:sans-serif;font-size:15px;line-height:1.8;color:#333;margin:0 0 20px;padding-left:20px;">
<li>Complete your profile</li>
<li>Browse our features</li>
<li>Reach out if you need help</li>
</ul>
<div style="text-align:center;margin:24px 0;">
<a href="#" style="display:inline-block;padding:13px 28px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:15px;font-weight:600;">Get Started</a>
</div>
<p style="font-family:sans-serif;font-size:14px;line-height:1.6;color:#6b7280;margin:0;">Warm regards,<br>The Team</p>
═══ END EXAMPLE ═══

Rules you MUST follow:
- Write the email body as if it will be sent directly to a recipient. It must read like a real email — greeting, content, CTA, sign-off.
- Do NOT write marketing strategies, bullet-point plans, campaign ideas, or advice. Write the actual email text.
- Use ONLY HTML tags with inline style= attributes. No markdown (no ##, no **, no dashes for lists).
- Do NOT include <html>, <head>, or <body> tags.
- Tone: {$tone}.
- Use {{contact.first_name}} naturally for personalisation.
- Keep it concise: 150–300 words in the body.
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => trim("{$campaignCtx} {$validated['prompt']}")],
        ];

        try {
            $response = $llm->chat($messages, ['system' => $systemPrompt, 'max_tokens' => 2000]);
            $parsed = $this->parseEmailResponse($response->content);

            // If the body looks like strategy/plan content rather than an email, retry once
            // with an even more explicit instruction prepended to the user message.
            if ($parsed && $this->looksLikeStrategy($parsed['body'])) {
                $retryMessages = [
                    [
                        'role' => 'user',
                        'content' => 'Write the actual email (not a strategy or plan). '
                            .trim("{$campaignCtx} {$validated['prompt']}"),
                    ],
                ];
                $retryResponse = $llm->chat($retryMessages, ['system' => $systemPrompt, 'max_tokens' => 2000]);
                $retryParsed = $this->parseEmailResponse($retryResponse->content);
                if ($retryParsed && ! $this->looksLikeStrategy($retryParsed['body'])) {
                    $parsed = $retryParsed;
                }
            }

            if (! $parsed) {
                return response()->json(['error' => 'AI returned an unexpected format. Try rephrasing your prompt.'], 422);
            }

            return response()->json($parsed);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI generation failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Parse an AI email response into {subject, body}.
     * Tries multiple formats in order of preference.
     */
    private function parseEmailResponse(string $raw): ?array
    {
        $raw = trim($raw);

        // Strip markdown fences
        $raw = preg_replace('/^```[a-z]*\s*/i', '', $raw);
        $raw = preg_replace('/\s*```\s*$/', '', trim($raw));

        // Format 1: SUBJECT: … \n BODY: \n …
        if (preg_match('/SUBJECT:\s*(.+?)\s*\nBODY:\s*\n?([\s\S]+)$/i', $raw, $m)) {
            return [
                'subject' => trim($m[1]),
                'body' => $this->ensureHtml(trim($m[2])),
            ];
        }

        // Format 2: valid JSON {subject, body}
        $json = json_decode($raw, true);
        if (is_array($json) && ! empty($json['subject']) && ! empty($json['body'])) {
            return [
                'subject' => $json['subject'],
                'body' => $this->ensureHtml($json['body']),
            ];
        }

        // Format 3: first non-empty line = subject, rest = body
        $lines = array_values(array_filter(explode("\n", $raw), fn ($l) => trim($l) !== ''));
        if (count($lines) >= 2) {
            return [
                'subject' => strip_tags(trim($lines[0])),
                'body' => $this->ensureHtml(trim(implode("\n", array_slice($lines, 1)))),
            ];
        }

        return null;
    }

    /**
     * Detect when the model returned marketing strategy/plan content instead of an email.
     * Heuristic: checks for common strategy phrases in the first 300 chars.
     */
    private function looksLikeStrategy(string $body): bool
    {
        $plain = mb_strtolower(strip_tags($body));
        $excerpt = mb_substr($plain, 0, 400);

        $strategySignals = [
            'marketing strateg',
            'campaign strateg',
            'suggested strateg',
            'campaign detail',
            'social media post',
            'influencer',
            'target audience',
            'key performance',
            'marketing plan',
            'content calendar',
            'engagement rate',
            'call to action strategy',
            'campaign goal',
        ];

        $hits = 0;
        foreach ($strategySignals as $signal) {
            if (str_contains($excerpt, $signal)) {
                $hits++;
            }
        }

        // Also flag if it has no greeting-like opening
        $hasGreeting = preg_match('/\b(hi|hello|dear|hey|greetings|good (morning|afternoon|evening))\b/i', $excerpt);

        return $hits >= 2 || ($hits >= 1 && ! $hasGreeting);
    }

    /**
     * Parse and validate subject line suggestions.
     * Strips markdown, rejects lines that are too long or look like strategy content.
     */
    private function parseSubjectSuggestions(string $text): array
    {
        $results = [];

        // Try numbered format first: "1. " or "1) "
        preg_match_all('/^\s*\d+[.)]\s*(.+)/m', $text, $matches);
        $candidates = $matches[1] ?? [];

        // Fallback: one candidate per non-empty line
        if (count($candidates) === 0) {
            $candidates = array_filter(explode("\n", $text), fn ($l) => trim($l) !== '');
        }

        foreach ($candidates as $line) {
            // Strip markdown bold/italic and leading punctuation
            $clean = preg_replace('/\*{1,2}(.*?)\*{1,2}/', '$1', $line);
            $clean = trim($clean, " \t\n\r\0\x0B\"'*-•");

            // Skip empty or impossibly long lines (strategy content is always long)
            if ($clean === '' || mb_strlen($clean) > 80) {
                continue;
            }

            // Skip lines that contain strategy-like keywords — these are not subject lines
            $lower = mb_strtolower($clean);
            $strategyWords = ['utilize', 'platforms like', 'showcase', 'testimonial',
                'influencer', 'newsletter', 'bi-weekly', 'collaboration',
                'strategies', 'engagement rate', 'target audience', 'campaign goal'];

            $isStrategy = false;
            foreach ($strategyWords as $word) {
                if (str_contains($lower, $word)) {
                    $isStrategy = true;
                    break;
                }
            }
            if ($isStrategy) {
                continue;
            }

            $results[] = $clean;
        }

        return $results;
    }

    /**
     * If the content looks like markdown rather than HTML, convert it.
     * This handles models that ignore HTML instructions.
     */
    private function ensureHtml(string $content): string
    {
        // If it already contains HTML tags, trust it
        if (preg_match('/<[a-z][^>]*>/i', $content)) {
            return $content;
        }

        // Convert markdown to inline-styled HTML
        $html = $content;

        // Headings: ### → h3, ## → h2, # → h1
        $html = preg_replace_callback('/^(#{1,3})\s+(.+)$/m', function ($m) {
            $level = strlen($m[1]);
            $sizes = [1 => '22px', 2 => '18px', 3 => '16px'];
            $size = $sizes[$level];

            return "<h{$level} style=\"font-family:sans-serif;font-size:{$size};font-weight:700;color:#111;margin:16px 0 8px;\">{$m[2]}</h{$level}>";
        }, $html);

        // Bold: **text** → <strong>
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);

        // Italic: *text* → <em>
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);

        // Unordered list items: lines starting with - or *
        $html = preg_replace_callback('/^(\s*[-*•]\s+.+(\n\s*[-*•]\s+.+)*)/m', function ($m) {
            $lines = preg_split('/\n/', $m[0]);
            $items = '';
            foreach ($lines as $line) {
                $text = trim(preg_replace('/^[-*•]\s+/', '', trim($line)));
                $items .= "<li style=\"margin-bottom:6px;\">{$text}</li>";
            }

            return "<ul style=\"font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;margin:0 0 12px;padding-left:20px;\">{$items}</ul>";
        }, $html);

        // Wrap remaining bare text blocks in <p> tags (split on blank lines)
        $paragraphs = preg_split('/\n{2,}/', trim($html));
        $result = '';
        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            // Already an HTML tag — keep as-is
            if (preg_match('/^<[a-z]/i', $para)) {
                $result .= $para."\n";
            } else {
                // Convert single newlines within a paragraph to <br>
                $para = str_replace("\n", '<br>', $para);
                $result .= "<p style=\"font-family:sans-serif;font-size:15px;line-height:1.6;color:#333;margin:0 0 12px;\">{$para}</p>\n";
            }
        }

        return trim($result);
    }
}

<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ApiUsageController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = ApiRequestLog::forUser($user)->orderByDesc('id');

        $this->applyFilters($query, $request);

        $logs = $query->paginate(30)->withQueryString()->through(fn (ApiRequestLog $log) => [
            'id' => $log->id,
            'token_name' => $log->token_name,
            'method' => $log->method,
            'path' => $log->path,
            'status' => $log->status,
            'duration_ms' => $log->duration_ms,
            'ip' => $log->ip,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ]);

        $tokens = $user->tokens()->latest()->get(['id', 'name'])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name]);

        $props = [
            'logs' => $logs,
            'filters' => $request->only(['token_id', 'status', 'method', 'path', 'from', 'to']),
            'tokens' => $tokens,
            'stats' => $this->statsFor($user),
        ];

        if ($request->route('id')) {
            $props['selected'] = $this->detail($request, (int) $request->route('id'));
        }

        return Inertia::render('client/Api/Usage', $props);
    }

    public function show(Request $request, int $id): Response
    {
        // Reuse index() so the slide-over renders on the same page; `selected`
        // is added because the {id} route param is present.
        $request->route()->setParameter('id', $id);

        return $this->index($request);
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json($this->statsFor($request->user()));
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('token_id')) {
            $query->where('token_id', (int) $request->input('token_id'));
        }

        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->input('method')));
        }

        if ($request->filled('path')) {
            $query->where('path', 'like', '%'.$request->input('path').'%');
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            match ($status) {
                '2xx' => $query->whereBetween('status', [200, 299]),
                '3xx' => $query->whereBetween('status', [300, 399]),
                '4xx' => $query->whereBetween('status', [400, 499]),
                '5xx' => $query->whereBetween('status', [500, 599]),
                default => is_numeric($status) ? $query->where('status', (int) $status) : null,
            };
        }

        // Ignore unparseable date params rather than 500 — Request::date() throws
        // on garbage input and filled() only checks for non-empty.
        if ($request->filled('from') && ($from = $this->parseDate($request->input('from')))) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($request->filled('to') && ($to = $this->parseDate($request->input('to')))) {
            $query->where('created_at', '<=', $to->endOfDay());
        }
    }

    private function parseDate(mixed $value): ?Carbon
    {
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function detail(Request $request, int $id): array
    {
        $log = ApiRequestLog::forUser($request->user())->findOrFail($id);

        return [
            'id' => $log->id,
            'token_name' => $log->token_name,
            'method' => $log->method,
            'path' => $log->path,
            'route_name' => $log->route_name,
            'query' => $log->query,
            'status' => $log->status,
            'duration_ms' => $log->duration_ms,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'request_headers' => $log->request_headers,
            'request_body' => $log->request_body,
            'response_body' => $log->response_body,
            'response_size_bytes' => $log->response_size_bytes,
            'error_class' => $log->error_class,
            'created_at' => optional($log->created_at)->toIso8601String(),
        ];
    }

    private function statsFor(User $user): array
    {
        $key = 'api-usage-stats:'.($user->isClientAdministrator() ? 'c'.$user->client_id : 'u'.$user->id);

        return Cache::remember($key, 60, function () use ($user) {
            $base = fn () => ApiRequestLog::forUser($user)->where('created_at', '>=', now()->subDay());

            $total = (clone $base())->count();
            $errors = (clone $base())->where('status', '>=', 400)->count();

            $durations = (clone $base())->orderBy('duration_ms')->pluck('duration_ms')->all();
            $p95 = 0;
            if ($durations !== []) {
                $idx = (int) ceil(0.95 * count($durations)) - 1;
                $p95 = $durations[max($idx, 0)];
            }

            $topPaths = (clone $base())
                ->selectRaw('path, count(*) as c')
                ->groupBy('path')->orderByDesc('c')->limit(5)->get()
                ->map(fn ($r) => ['path' => $r->path, 'count' => (int) $r->c]);

            return [
                'calls_24h' => $total,
                'error_rate' => $total > 0 ? round($errors / $total, 4) : 0.0,
                'p95_ms' => (int) $p95,
                'top_paths' => $topPaths,
            ];
        });
    }
}

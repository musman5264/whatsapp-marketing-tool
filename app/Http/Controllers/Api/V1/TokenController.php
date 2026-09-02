<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Support\ApiAbilities;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    /**
     * Issue a new personal access token.
     * Used from the UI token management page.
     */
    public function store(Request $request): JsonResponse
    {
        $validAbilities = ApiAbilities::all();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'in:*,'.implode(',', $validAbilities)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $abilities = $validated['abilities'] ?? ['*'];
        $expiresAt = isset($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;

        $token = $request->user()->createToken($validated['name'], $abilities, $expiresAt);

        return response()->json([
            'id' => $token->accessToken->id,
            'name' => $validated['name'],
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ], 201);
    }

    /**
     * List all tokens for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
        ]);

        return response()->json(['data' => $tokens]);
    }

    /**
     * Revoke a token.
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        $user = $request->user();

        $token = $user->tokens()->where('id', $tokenId)->first();

        if (! $token) {
            return response()->json(['error' => 'Token not found.'], 404);
        }

        // Detach the token_id from its request-log history, then delete the
        // token. token_name stays on the log rows as a readable snapshot; the
        // rows themselves are never removed. This explicit update is the
        // reliable path — it does not depend on the PersonalAccessToken
        // "deleting" model event firing.
        ApiRequestLog::where('token_id', $token->getKey())
            ->update(['token_id' => null]);

        $token->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Return the list of available ability scopes (for the UI).
     */
    public function scopes(): JsonResponse
    {
        $labels = ApiAbilities::labels();

        return response()->json([
            'data' => array_map(fn ($scope, $label) => ['scope' => $scope, 'label' => $label], array_keys($labels), $labels),
        ]);
    }
}

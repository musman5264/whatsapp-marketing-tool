<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $tokens = $request->user()->tokens()->latest()->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'abilities' => $t->abilities,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'created_at' => $t->created_at->toIso8601String(),
        ]);

        return Inertia::render('client/Api/Tokens', [
            'tokens' => $tokens,
        ]);
    }
}

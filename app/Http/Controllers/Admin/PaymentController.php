<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {

        $query = PaymentTransaction::with(['user:id,name,email', 'subscription.plan']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('gateway')) {
            $query->where('gateway', $request->gateway);
        }

        $payments = $query->orderByDesc('created_at')->paginate(20)->withQueryString()->through(fn (PaymentTransaction $t) => [
            'id' => $t->id,
            'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email] : null,
            'gateway' => $t->gateway,
            'amount_cents' => $t->amount_cents,
            'currency_code' => $t->currency_code,
            'status' => $t->status,
            'created_at' => $t->created_at->toIso8601String(),
            'plan' => $t->subscription?->plan ? ['name' => $t->subscription->plan->name] : null,
        ]);

        return Inertia::render('Admin/Payments/Index', [
            'payments' => $payments,
            'filters' => $request->only(['status', 'gateway']),
        ]);
    }
}

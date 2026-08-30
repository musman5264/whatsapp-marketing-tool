<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientSubscription;
use App\Models\Coupon;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function __construct(
        protected BillingGatewayRegistry $gateways,
        protected InvoiceService $invoiceService
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $effective = $user->effectiveSubscription();

        $subscription = null;
        $canCancel = false;
        $canUpgrade = true;

        if ($effective) {
            $plan = $effective->plan;
            $isClientSubscription = $effective instanceof ClientSubscription;
            $canCancel = ! $isClientSubscription && $effective instanceof Subscription && $effective->isActive();
            $canUpgrade = ! $isClientSubscription;

            $renewsAt = null;
            $endsAt = null;
            $trialEndsAt = null;
            if ($effective instanceof Subscription) {
                $renewsAt = $effective->renews_at?->toIso8601String();
                $endsAt = $effective->ends_at?->toIso8601String();
                $trialEndsAt = $effective->trial_ends_at?->toIso8601String();
            }
            if ($effective instanceof ClientSubscription) {
                $endsAt = $effective->ends_at?->toIso8601String();
            }

            $subscription = [
                'plan' => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'trial_days' => $plan->trial_days ?? 0,
                ],
                'billing_cycle' => $effective instanceof Subscription ? $effective->billing_cycle : null,
                'status' => $effective->status ?? ($effective->isActive() ? 'active' : 'inactive'),
                'renews_at' => $renewsAt,
                'ends_at' => $endsAt,
                'trial_ends_at' => $trialEndsAt,
                'gateway' => $effective instanceof Subscription ? $effective->gateway : null,
                'managed_by_admin' => $isClientSubscription,
            ];
        }

        $plans = Plan::where('enabled', true)->orderBy('sort_order')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'monthly_price' => $p->monthly_price,
            'annual_price' => $p->annual_price,
        ]);

        $transactions = $user->paymentTransactions()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'amount_cents' => $t->amount_cents,
                'currency_code' => $t->currency_code,
                'status' => $t->status,
                'refunded_at' => $t->refunded_at?->toIso8601String(),
                'refunded_cents' => $t->refunded_cents,
                'created_at' => $t->created_at->toIso8601String(),
                'invoice_url' => $t->invoice_path ? route('client.subscription.invoice', $t->id) : null,
            ]);

        return Inertia::render('client/Subscription/Show', [
            'subscription' => $subscription,
            'canCancel' => $canCancel,
            'canUpgrade' => $canUpgrade,
            'plans' => $plans,
            'transactions' => $transactions,
        ]);
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['required', Rule::in(['month', 'year'])],
        ]);

        $user = $request->user();
        $effective = $user->effectiveSubscription();

        if (! $effective instanceof Subscription) {
            return back()->with('error', __('Subscription is managed by your organization.'));
        }

        if (! $effective->isActive()) {
            return back()->with('error', __('No active subscription to change.'));
        }

        $newPlan = Plan::where('enabled', true)->findOrFail($validated['plan_id']);

        if ($newPlan->id === $effective->plan_id && $validated['billing_cycle'] === $effective->billing_cycle) {
            return back()->with('error', __('You are already on this plan and billing cycle.'));
        }

        $gateway = $this->gateways->get($effective->gateway ?? 'stripe');
        if (! $gateway) {
            return back()->with('error', __('Billing gateway not configured.'));
        }

        $result = $gateway->changePlan($effective, $newPlan, $validated['billing_cycle']);

        if (! $result['ok']) {
            return back()->with('error', $result['error'] ?? __('Could not change plan.'));
        }

        return redirect()->route('client.subscription.show')
            ->with('success', __('Your plan has been updated.'));
    }

    public function couponCheck(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'plan_id' => ['nullable', 'integer'],
        ]);

        $coupon = Coupon::where('code', $validated['code'])->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json(['valid' => false, 'message' => __('Invalid or expired coupon code.')]);
        }

        if ($coupon->applies_to_plan_ids && ! empty($coupon->applies_to_plan_ids)) {
            $planId = $validated['plan_id'] ?? null;
            if (! $planId || ! in_array((int) $planId, $coupon->applies_to_plan_ids)) {
                return response()->json(['valid' => false, 'message' => __('This coupon does not apply to the selected plan.')]);
            }
        }

        return response()->json([
            'valid' => true,
            'kind' => $coupon->kind,
            'amount' => $coupon->amount,
            'duration' => $coupon->duration,
        ]);
    }

    public function invoiceDownload(Request $request, int $transactionId): HttpResponse|RedirectResponse
    {
        $user = $request->user();
        $transaction = PaymentTransaction::where('user_id', $user->id)->findOrFail($transactionId);

        if ($transaction->invoice_path && file_exists(storage_path('app/' . $transaction->invoice_path))) {
            return response()->file(storage_path('app/' . $transaction->invoice_path), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="invoice-' . $transaction->id . '.pdf"',
            ]);
        }

        // Generate on demand
        $pdf = $this->invoiceService->generate($transaction);
        if (! $pdf) {
            return back()->with('error', __('Invoice could not be generated.'));
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="invoice-' . $transaction->id . '.pdf"',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $effective = $user->effectiveSubscription();

        if (! $effective instanceof Subscription) {
            return redirect()->route('client.subscription.show')
                ->with('error', __('Subscription is managed by your organization.'));
        }

        if (! $effective->isActive()) {
            return redirect()->route('client.subscription.show')
                ->with('error', __('No active subscription to cancel.'));
        }

        $gateway = $this->gateways->get($effective->gateway ?? 'stripe');
        if (! $gateway || ! $gateway->cancel($effective)) {
            return redirect()->route('client.subscription.show')
                ->with('error', __('Could not cancel subscription. Please contact support.'));
        }

        return redirect()->route('client.subscription.show')
            ->with('success', __('Your subscription has been cancelled.'));
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function index(): Response
    {
        $coupons = Coupon::latest()->paginate(25);

        return Inertia::render('Admin/Coupons/Index', [
            'coupons' => $coupons,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('coupons', 'code')],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', Rule::in(['once', 'repeating', 'forever'])],
            'duration_in_months' => ['nullable', 'integer', 'min:1'],
            'applies_to_plan_ids' => ['nullable', 'array'],
            'applies_to_plan_ids.*' => ['integer'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        Coupon::create($validated);

        return redirect()->route('admin.coupons.index')
            ->with('success', __('Coupon created.'));
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('coupons', 'code')->ignore($coupon->id)],
            'kind' => ['required', Rule::in(['percent', 'fixed'])],
            'amount' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', Rule::in(['once', 'repeating', 'forever'])],
            'duration_in_months' => ['nullable', 'integer', 'min:1'],
            'applies_to_plan_ids' => ['nullable', 'array'],
            'applies_to_plan_ids.*' => ['integer'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'enabled' => ['boolean'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $coupon->update($validated);

        return redirect()->route('admin.coupons.index')
            ->with('success', __('Coupon updated.'));
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $coupon->delete();

        return redirect()->route('admin.coupons.index')
            ->with('success', __('Coupon deleted.'));
    }
}

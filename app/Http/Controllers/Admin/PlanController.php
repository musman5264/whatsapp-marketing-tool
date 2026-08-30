<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public static function defaultLimits(): array
    {
        return [
            'users' => null,
            'storage' => null,
        ];
    }

    /** Enabled currencies for the plan form dropdown. */
    private function currencyOptions(): array
    {
        return Currency::where('enabled', true)
            ->orderBy('code')
            ->get(['code', 'symbol'])
            ->map(fn (Currency $c) => ['code' => $c->code, 'symbol' => $c->symbol])
            ->all();
    }

    private function planToArray(Plan $p): array
    {
        $limits = $p->limits;
        if (! is_array($limits)) {
            $limits = self::defaultLimits();
        }

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'description' => $p->description,
            'currency_code' => $p->currency_code,
            'monthly_price_cents' => $p->monthly_price_cents,
            'yearly_price_cents' => $p->yearly_price_cents,
            'trial_days' => (int) ($p->trial_days ?? 0),
            'stripe_monthly_id' => $p->stripe_monthly_id,
            'stripe_yearly_id' => $p->stripe_yearly_id,
            'features' => is_array($p->features) ? $p->features : [],
            'limits' => $limits,
            'enabled' => (bool) $p->enabled,
            'featured' => (bool) ($p->featured ?? false),
            'popular' => (bool) ($p->popular ?? false),
            'sort_order' => (int) $p->sort_order,
            'white_label_enabled' => (bool) ($p->white_label_enabled ?? false),
        ];
    }

    public function index(Request $request): Response
    {
        $plans = Plan::orderBy('sort_order')->orderBy('id')->get()->map(fn (Plan $p) => $this->planToArray($p));

        return Inertia::render('Admin/Plans/Index', [
            'plans' => $plans,
            'currencies' => $this->currencyOptions(),
            'defaultCurrency' => Currency::defaultCode() ?? 'USD',
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validatePlan($request, null);
        $validated['slug'] = $validated['slug'] ?: Str::slug($validated['name']);
        $validated['sort_order'] = (int) (Plan::max('sort_order') ?? 0) + 1;

        Plan::create($this->mapValidatedToAttributes($validated));

        return redirect()->route('admin.plans.index')->with('success', __('Plan created successfully.'));
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('Admin/Plans/Edit', [
            'plan' => $this->planToArray($plan),
            'currencies' => $this->currencyOptions(),
            'defaultCurrency' => Currency::defaultCode() ?? 'USD',
        ]);
    }

    public function update(Request $request, Plan $plan): \Illuminate\Http\RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);
        $plan->update($this->mapValidatedToAttributes($validated));

        return redirect()->route('admin.plans.index')->with('success', __('Plan updated successfully.'));
    }

    public function destroy(Plan $plan): \Illuminate\Http\RedirectResponse
    {
        $plan->delete();

        return redirect()->route('admin.plans.index')->with('success', __('Plan deleted successfully.'));
    }

    public function duplicate(Plan $plan): \Illuminate\Http\RedirectResponse
    {
        $copy = $plan->replicate();
        $copy->name = $plan->name.' (Copy)';
        $copy->slug = $plan->slug.'-copy-'.Str::random(4);
        $copy->sort_order = (int) (Plan::max('sort_order') ?? 0) + 1;
        $copy->save();

        return redirect()->route('admin.plans.index')
            ->with('success', __('Plan duplicated successfully.'))
            ->with('openEditPlanId', $copy->id);
    }

    public function reorder(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer', 'exists:plans,id']]);

        foreach ($request->input('order') as $position => $id) {
            Plan::where('id', $id)->update(['sort_order' => $position]);
        }

        return redirect()->route('admin.plans.index')->with('success', __('Plans reordered.'));
    }

    private function validatePlan(Request $request, ?Plan $plan = null): array
    {
        $request->merge(['currency_code' => strtoupper(trim((string) $request->input('currency_code')))]);

        $limitsKeys = array_keys(self::defaultLimits());
        $slugRule = ['nullable', 'string', 'max:64'];
        if ($plan) {
            $slugRule[] = 'unique:plans,slug,'.$plan->id;
        } else {
            $slugRule[] = 'unique:plans,slug';
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'slug' => $slugRule,
            'description' => ['nullable', 'string', 'max:1000'],
            'currency_code' => ['required', 'string', 'max:10', Rule::exists('currencies', 'code')],
            'monthly_price_cents' => ['required', 'integer', 'min:0'],
            'yearly_price_cents' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'stripe_monthly_id' => ['nullable', 'string', 'max:255'],
            'stripe_yearly_id' => ['nullable', 'string', 'max:255'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:500'],
            'enabled' => ['boolean'],
            'featured' => ['boolean'],
            'popular' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'white_label_enabled' => ['boolean'],
        ];

        foreach ($limitsKeys as $key) {
            $rules['limits.'.$key] = ['nullable', 'integer', 'min:0'];
        }

        return $request->validate($rules);
    }

    private function mapValidatedToAttributes(array $validated): array
    {
        $monthly = (int) ($validated['monthly_price_cents'] ?? 0);

        return [
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'currency_code' => $validated['currency_code'],
            'price_cents' => $monthly,
            'interval' => 'month',
            'monthly_price_cents' => $monthly,
            'yearly_price_cents' => isset($validated['yearly_price_cents']) ? (int) $validated['yearly_price_cents'] : null,
            'trial_days' => (int) ($validated['trial_days'] ?? 0),
            'stripe_monthly_id' => $validated['stripe_monthly_id'] ?? null,
            'stripe_yearly_id' => $validated['stripe_yearly_id'] ?? null,
            'features' => $validated['features'] ?? [],
            'limits' => $validated['limits'] ?? null,
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'featured' => (bool) ($validated['featured'] ?? false),
            'popular' => (bool) ($validated['popular'] ?? false),
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
            'white_label_enabled' => (bool) ($validated['white_label_enabled'] ?? false),
        ];
    }
}

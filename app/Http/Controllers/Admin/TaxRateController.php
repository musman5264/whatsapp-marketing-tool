<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxRateController extends Controller
{
    public function index(): Response
    {
        $taxRates = TaxRate::orderBy('country')->orderBy('region')->get();

        return Inertia::render('Admin/TaxRates/Index', [
            'taxRates' => $taxRates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:100'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'inclusive' => ['boolean'],
            'enabled' => ['boolean'],
        ]);

        TaxRate::create($validated);

        return redirect()->route('admin.tax-rates.index')
            ->with('success', __('Tax rate created.'));
    }

    public function update(Request $request, TaxRate $taxRate): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'],
            'region' => ['nullable', 'string', 'max:100'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'inclusive' => ['boolean'],
            'enabled' => ['boolean'],
        ]);

        $taxRate->update($validated);

        return redirect()->route('admin.tax-rates.index')
            ->with('success', __('Tax rate updated.'));
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $taxRate->delete();

        return redirect()->route('admin.tax-rates.index')
            ->with('success', __('Tax rate deleted.'));
    }
}

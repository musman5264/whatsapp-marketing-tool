<?php

namespace App\Http\Controllers\Client\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateWorkspaceExportJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataExportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('client/Settings/DataExport', [
            'status' => session('export_status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        GenerateWorkspaceExportJob::dispatch($request->user()->id)
            ->onQueue('default');

        return back()->with('export_status', 'Your export is being generated. You will receive an email with the download link shortly.');
    }
}

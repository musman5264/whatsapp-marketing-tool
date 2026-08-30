<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Services\ActivityRecorder;
use App\Modules\Shared\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $leads = Lead::where('workspace_id', $wid)
            ->latest()
            ->paginate(50);

        $scrapeJobs = LeadScrapeJob::where('workspace_id', $wid)
            ->latest()->limit(10)->get();

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'scrapeJobs' => $scrapeJobs,
        ]);
    }

    public function scrape(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:256'],
            'location' => ['required', 'string', 'max:256'],
            'radius_meters' => ['nullable', 'integer', 'min:100', 'max:50000'],
        ]);

        $job = LeadScrapeJob::create(array_merge($validated, [
            'workspace_id' => $wid,
            'radius_meters' => $validated['radius_meters'] ?? 5000,
            'status' => 'pending',
        ]));

        ScrapeLeadsJob::dispatch($job->id)->onQueue('leads');

        return back()->with('success', 'Scrape job started. Results will appear shortly.');
    }

    public function pushToContacts(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $ids = $request->validate(['ids' => ['required', 'array']])['ids'];

        $leads = Lead::where('workspace_id', $wid)->whereIn('id', $ids)->where('pushed_to_contacts', false)->get();

        foreach ($leads as $lead) {
            if (! $lead->phone && ! $lead->email) {
                continue;
            }

            $nameParts = explode(' ', $lead->name ?? '', 2);
            $contact = Contact::firstOrCreate(
                ['workspace_id' => $wid, 'phone_e164' => $lead->phone],
                [
                    'first_name' => $nameParts[0] ?? null,
                    'last_name' => $nameParts[1] ?? null,
                    'email' => $lead->email,
                    'source' => 'lead_scraper',
                    'lead_id' => $lead->id,
                ]
            );

            // An existing contact predates this lead and so has no lead_id. Without
            // the link, pipeline automations have no contact to run against.
            if (! $contact->lead_id) {
                $contact->update(['lead_id' => $lead->id]);
            }

            $lead->update(['pushed_to_contacts' => true]);
            $this->activity->pushedToContacts($lead, (int) $contact->id);
        }

        return back()->with('success', count($leads).' lead(s) pushed to contacts.');
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);
        $lead->delete();

        return back()->with('success', 'Lead deleted.');
    }
}

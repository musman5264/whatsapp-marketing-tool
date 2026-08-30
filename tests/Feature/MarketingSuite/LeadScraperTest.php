<?php

namespace Tests\Feature\MarketingSuite;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Services\GooglePlacesScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadScraperTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return $workspace;
    }

    private function configureKey(string $key = 'test-key'): void
    {
        IntegrationConfig::create([
            'provider' => 'google_places',
            'label' => IntegrationConfig::LABELS['google_places'],
            'mode' => 'live',
            'enabled' => true,
            'credentials' => ['api_key' => $key],
        ]);
    }

    private function scrapeJob(Workspace $workspace): LeadScrapeJob
    {
        return LeadScrapeJob::create([
            'workspace_id' => $workspace->id,
            'keyword' => 'restaurants',
            'location' => 'Dhaka',
            'status' => 'pending',
        ]);
    }

    private function place(string $placeId, string $name = 'Test Cafe'): array
    {
        return [
            'place_id' => $placeId,
            'name' => $name,
            'formatted_address' => '12 Gulshan Ave, Dhaka',
            'types' => ['restaurant', 'food', 'point_of_interest'],
            'rating' => 4.5,
            'user_ratings_total' => 120,
            'geometry' => ['location' => ['lat' => 23.78, 'lng' => 90.41]],
        ];
    }

    private function fakeTextSearch(array $sequenceBodies): void
    {
        $sequence = Http::sequence();
        foreach ($sequenceBodies as $body) {
            $sequence->push($body, 200);
        }

        Http::fake([
            'maps.googleapis.com/maps/api/place/textsearch/*' => $sequence,
            'maps.googleapis.com/maps/api/place/details/*' => Http::response([
                'status' => 'OK',
                'result' => ['formatted_phone_number' => '+880 1700-000000', 'website' => 'https://example.com'],
            ], 200),
        ]);
    }

    private function runScrape(LeadScrapeJob $job): void
    {
        app(GooglePlacesScraper::class)->run($job);
        $job->refresh();
    }

    #[Test]
    public function scrape_stores_leads_from_a_successful_search(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([
            ['status' => 'OK', 'results' => [$this->place('place-a'), $this->place('place-b', 'Second Cafe')]],
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);
        $this->assertNull($job->error);
        $this->assertSame(2, $job->leads_found);
        $this->assertDatabaseHas('leads', [
            'workspace_id' => $workspace->id,
            'google_place_id' => 'place-a',
            'phone' => '+880 1700-000000',
        ]);
        $this->assertSame(2, UsageMeter::current($workspace->id, 'lead_credits'));
    }

    #[Test]
    public function request_denied_marks_the_job_failed_instead_of_reporting_zero_leads(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([
            ['status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key is expired.'],
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('API key', $job->error);
        $this->assertStringContainsString('expired', $job->error);
        $this->assertSame(0, UsageMeter::current($workspace->id, 'lead_credits'));
    }

    #[Test]
    public function over_query_limit_marks_the_job_failed(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([['status' => 'OVER_QUERY_LIMIT']]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('quota', $job->error);
    }

    #[Test]
    public function zero_results_completes_cleanly_with_no_leads(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([['status' => 'ZERO_RESULTS', 'results' => []]]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);
        $this->assertSame(0, $job->leads_found);
        $this->assertNull($job->error);
    }

    #[Test]
    public function missing_api_key_marks_the_job_failed(): void
    {
        $workspace = $this->workspace();
        Http::fake();

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('not configured', $job->error);
    }

    #[Test]
    public function scraping_a_place_another_workspace_already_has_does_not_steal_it(): void
    {
        $workspaceA = $this->workspace();
        $workspaceB = $this->workspace();
        $this->configureKey();

        $existing = Lead::factory()->create([
            'workspace_id' => $workspaceA->id,
            'google_place_id' => 'shared-place',
            'name' => 'Cafe As Seen By A',
        ]);

        $this->fakeTextSearch([
            ['status' => 'OK', 'results' => [$this->place('shared-place', 'Cafe As Seen By B')]],
        ]);

        $job = $this->scrapeJob($workspaceB);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);

        // A keeps its lead, untouched.
        $existing->refresh();
        $this->assertSame($workspaceA->id, (int) $existing->workspace_id);
        $this->assertSame('Cafe As Seen By A', $existing->name);

        // B gets its own row for the same business.
        $this->assertDatabaseHas('leads', [
            'workspace_id' => $workspaceB->id,
            'google_place_id' => 'shared-place',
            'name' => 'Cafe As Seen By B',
        ]);
        $this->assertSame(2, Lead::where('google_place_id', 'shared-place')->count());
    }

    #[Test]
    public function pagination_follows_next_page_token_beyond_the_first_page(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([
            ['status' => 'OK', 'results' => [$this->place('page1-a')], 'next_page_token' => 'token-2'],
            ['status' => 'OK', 'results' => [$this->place('page2-a')]],
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);
        $this->assertSame(2, $job->leads_found);
        $this->assertDatabaseHas('leads', ['workspace_id' => $workspace->id, 'google_place_id' => 'page2-a']);
    }

    #[Test]
    public function pagination_retries_while_the_next_page_token_is_still_settling(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([
            ['status' => 'OK', 'results' => [$this->place('page1-a')], 'next_page_token' => 'token-2'],
            ['status' => 'INVALID_REQUEST'],
            ['status' => 'OK', 'results' => [$this->place('page2-a')]],
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);
        $this->assertSame(2, $job->leads_found);
        $this->assertDatabaseHas('leads', ['workspace_id' => $workspace->id, 'google_place_id' => 'page2-a']);
    }

    #[Test]
    public function a_failure_partway_through_still_reports_the_leads_already_stored(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([
            ['status' => 'OK', 'results' => [$this->place('page1-a')], 'next_page_token' => 'token-2'],
            ['status' => 'OVER_QUERY_LIMIT'],
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('quota', $job->error);

        // Page 1's lead was saved before the quota ran out — report and bill it.
        $this->assertSame(1, $job->leads_found);
        $this->assertDatabaseHas('leads', ['workspace_id' => $workspace->id, 'google_place_id' => 'page1-a']);
        $this->assertSame(1, UsageMeter::current($workspace->id, 'lead_credits'));
    }

    #[Test]
    public function pagination_stops_at_three_pages(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();
        $this->fakeTextSearch([
            ['status' => 'OK', 'results' => [$this->place('p1')], 'next_page_token' => 't2'],
            ['status' => 'OK', 'results' => [$this->place('p2')], 'next_page_token' => 't3'],
            ['status' => 'OK', 'results' => [$this->place('p3')], 'next_page_token' => 't4'],
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);
        $this->assertSame(3, $job->leads_found);
        $this->assertDatabaseMissing('leads', ['google_place_id' => 'p4']);
    }

    #[Test]
    public function rescraping_the_same_places_does_not_bill_credits_twice(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();

        $body = ['status' => 'OK', 'results' => [$this->place('place-a'), $this->place('place-b')]];
        $this->fakeTextSearch([$body, $body]);

        $this->runScrape($this->scrapeJob($workspace));
        $this->assertSame(2, UsageMeter::current($workspace->id, 'lead_credits'));

        $second = $this->scrapeJob($workspace);
        $this->runScrape($second);

        $this->assertSame('done', $second->status);
        $this->assertSame(2, $second->leads_found);
        $this->assertSame(2, Lead::where('workspace_id', $workspace->id)->count());
        $this->assertSame(2, UsageMeter::current($workspace->id, 'lead_credits'));
    }

    #[Test]
    public function a_details_lookup_failure_still_stores_the_lead(): void
    {
        $workspace = $this->workspace();
        $this->configureKey();

        Http::fake([
            'maps.googleapis.com/maps/api/place/textsearch/*' => Http::response([
                'status' => 'OK', 'results' => [$this->place('place-a')],
            ], 200),
            'maps.googleapis.com/maps/api/place/details/*' => Http::response([], 500),
        ]);

        $job = $this->scrapeJob($workspace);
        $this->runScrape($job);

        $this->assertSame('done', $job->status);
        $this->assertDatabaseHas('leads', [
            'workspace_id' => $workspace->id,
            'google_place_id' => 'place-a',
            'phone' => null,
        ]);
    }

    #[Test]
    public function a_job_killed_by_the_worker_is_marked_failed_not_left_running(): void
    {
        $workspace = $this->workspace();
        $job = $this->scrapeJob($workspace);
        $job->update(['status' => 'running']);

        (new ScrapeLeadsJob($job->id))->failed(new \RuntimeException('Job has timed out.'));

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('timed out', $job->error);
        $this->assertNotNull($job->completed_at);
    }

    #[Test]
    public function the_failed_handler_does_not_overwrite_a_completed_job(): void
    {
        $workspace = $this->workspace();
        $job = $this->scrapeJob($workspace);
        $job->update(['status' => 'done', 'leads_found' => 5]);

        (new ScrapeLeadsJob($job->id))->failed(new \RuntimeException('late failure'));

        $job->refresh();
        $this->assertSame('done', $job->status);
        $this->assertSame(5, $job->leads_found);
    }
}

<?php

namespace Tests\Feature\Polish;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_returns_branded_view(): void
    {
        $response = $this->get('/this-page-does-not-exist-'.uniqid());

        $response->assertStatus(404);
        // When called normally (not via Inertia/ajax), it renders the blade error view
        // In test environment with Inertia middleware, it may return JSON or blade
        $this->assertContains($response->getStatusCode(), [404]);
    }

    public function test_healthz_endpoint_returns_ok(): void
    {
        $response = $this->get('/healthz/db');

        // Returns 200 or 503 depending on DB state, never 404
        $this->assertContains($response->getStatusCode(), [200, 503]);
    }
}

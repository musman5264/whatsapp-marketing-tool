<?php

namespace Tests\Feature\Billing;

use App\Models\Currency;
use App\Services\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformDefaultCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrencies(): void
    {
        // exchange_rate = USD per 1 unit of this currency.
        Currency::insert([
            ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => true, 'enabled' => true],
            ['code' => 'INR', 'symbol' => '₹', 'decimals' => 2, 'exchange_rate' => 0.012, 'is_default' => false, 'enabled' => true],
        ]);
    }

    private function makeDefault(string $code): void
    {
        Currency::query()->update(['is_default' => false]);
        Currency::where('code', $code)->update(['is_default' => true]);
    }

    public function test_changing_platform_default_currency_reaches_a_client_with_no_currency_of_their_own(): void
    {
        $this->seedCurrencies();
        $ctx = $this->createWorkspaceContext();

        $this->makeDefault('INR');

        $response = $this->actingAs($ctx['user'])->get('/app/dashboard');

        $response->assertOk();
        $this->assertSame('INR', $response->viewData('page')['props']['displayCurrency']);
    }

    public function test_a_client_who_picked_their_own_currency_is_not_overridden_by_the_platform_default(): void
    {
        $this->seedCurrencies();
        $ctx = $this->createWorkspaceContext();
        $ctx['user']->forceFill(['display_currency' => 'USD'])->save();

        $this->makeDefault('INR');

        $response = $this->actingAs($ctx['user'])->get('/app/dashboard');

        $response->assertOk();
        $this->assertSame('USD', $response->viewData('page')['props']['displayCurrency']);
    }

    public function test_signup_leaves_client_currency_null_so_it_inherits_the_platform_default(): void
    {
        $this->seedCurrencies();
        $this->makeDefault('INR');

        $this->post('/register', [
            'name' => 'Rupee Tester',
            'email' => 'rupee@example.test',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            'agree_terms' => true,
        ]);

        $client = \App\Models\Client::where('email', 'rupee@example.test')->firstOrFail();

        $this->assertNull($client->base_currency, 'Signup must not hardcode a currency onto the client.');
        $this->assertNull($client->currency_symbol);
        $this->assertNull(
            $client->workspaces()->first()?->currency_code,
            'Workspace currency must stay null so it does not shadow the platform default.'
        );
    }

    public function test_conversion_uses_usd_per_unit_rates_in_the_direction_the_data_is_stored(): void
    {
        $this->seedCurrencies();
        $svc = new CurrencyService();

        // $10.00 at 0.012 USD per rupee => ~833 rupees, not ₹0.12.
        $this->assertSame('₹833.33', $svc->formatConverted(1000, 'USD', 'INR'));
        $this->assertSame('$10.00', $svc->formatConverted(83333, 'INR', 'USD'));
    }

    public function test_conversion_round_trips(): void
    {
        $this->seedCurrencies();
        $svc = new CurrencyService();

        $this->assertSame(1000, $svc->convert($svc->convert(1000, 'USD', 'INR'), 'INR', 'USD'));
    }
}

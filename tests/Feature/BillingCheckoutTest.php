<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_checkout(): void
    {
        $plan = Plan::factory()->create();
        $response = $this->post(route('client.checkout.store'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
        ]);
        $response->assertRedirect(route('login', [], false));
    }

    public function test_checkout_requires_valid_plan(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $response = $this->actingAs($user)->post(route('client.checkout.store'), [
            'plan_id' => 99999,
            'billing_cycle' => 'month',
            'gateway' => 'stripe',
        ]);
        $response->assertSessionHasErrors('plan_id');
    }

    public function test_checkout_requires_valid_billing_cycle(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $plan = Plan::factory()->create();
        $response = $this->actingAs($user)->post(route('client.checkout.store'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'invalid',
            'gateway' => 'stripe',
        ]);
        $response->assertSessionHasErrors('billing_cycle');
    }

    public function test_checkout_requires_valid_gateway(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $plan = Plan::factory()->create();
        $response = $this->actingAs($user)->post(route('client.checkout.store'), [
            'plan_id' => $plan->id,
            'billing_cycle' => 'month',
            'gateway' => 'invalid',
        ]);
        $response->assertSessionHasErrors('gateway');
    }
}
